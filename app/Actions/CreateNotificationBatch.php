<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTO\CreateNotificationBatchDTO;
use App\DTO\NotificationBatchCreationResult;
use App\Enums\NotificationStatus;
use App\Enums\OutboxMessageStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotencyLockTimeoutException;
use App\Http\Resources\NotificationBatchResource;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\OutboxMessage;
use App\Services\Notifications\NotificationBatchPayloadHasher;
use App\Services\Notifications\NotificationKafkaPayloadBuilder;
use App\Services\Notifications\NotificationKafkaTopicResolver;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class CreateNotificationBatch
{
    public function __construct(
        private readonly NotificationBatchPayloadHasher $payloadHasher,
        private readonly NotificationKafkaTopicResolver $topicResolver,
        private readonly NotificationKafkaPayloadBuilder $kafkaPayloadBuilder,
    ) {}

    public function __invoke(CreateNotificationBatchDTO $data, Request $request): NotificationBatchCreationResult
    {
        $payloadHash = $this->payloadHasher->payloadHash($data);

        Log::info('Notification batch creation started.', [
            'idempotency_key' => $data->idempotencyKey,
            'payload_hash' => $payloadHash,
            'channel' => $data->channel->value,
            'priority' => $data->priority->value,
            'recipient_count' => count($data->recipientIds),
        ]);

        $lock = Cache::lock($this->lockName($data->idempotencyKey), 10);

        try {
            $result = $lock->block(3, fn (): NotificationBatchCreationResult => $this->createOrReplay($data, $payloadHash, $request));
        } catch (LockTimeoutException) {
            Log::warning('Idempotency lock acquisition timed out.', [
                'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $payloadHash,
            ]);

            throw new IdempotencyLockTimeoutException($data->idempotencyKey, $payloadHash);
        } catch (Throwable $exception) {
            if ($exception instanceof IdempotencyConflictException) {
                throw $exception;
            }

            Log::error('Notification batch idempotent creation failed.', [
                'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $payloadHash,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        if (! $result instanceof NotificationBatchCreationResult) {
            throw new LogicException('Idempotent notification batch creation returned an unexpected result.');
        }

        return $result;
    }

    private function createOrReplay(
        CreateNotificationBatchDTO $data,
        string $payloadHash,
        Request $request,
    ): NotificationBatchCreationResult {
        $existingIdempotencyKey = IdempotencyKey::query()
            ->where('key', $data->idempotencyKey)
            ->first();

        if ($existingIdempotencyKey !== null) {
            if ($existingIdempotencyKey->payload_hash !== $payloadHash) {
                Log::warning('Idempotency payload hash conflict detected.', [
                    'idempotency_key' => $data->idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'existing_payload_hash' => $existingIdempotencyKey->payload_hash,
                ]);

                throw new IdempotencyConflictException($data->idempotencyKey, $payloadHash);
            }

            if ($existingIdempotencyKey->response_body !== null && $existingIdempotencyKey->response_status !== null) {
                Log::info('Notification batch creation replayed from idempotency store.', [
                    'idempotency_key' => $data->idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'batch_id' => $existingIdempotencyKey->notification_batch_id,
                ]);

                return new NotificationBatchCreationResult(
                    responseBody: $existingIdempotencyKey->response_body,
                    responseStatus: $existingIdempotencyKey->response_status,
                );
            }
        }

        $result = DB::transaction(function () use ($data, $payloadHash, $request): NotificationBatchCreationResult {
            $batch = NotificationBatch::create([
                'channel' => $data->channel,
                'message' => $data->message,
                'priority' => $data->priority,
                'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $payloadHash,
                'status' => NotificationStatus::Queued,
            ]);

            $batch->notifications()->createMany(
                collect($data->recipientIds)
                    ->map(fn (string $recipientId): array => [
                        'recipient_id' => $recipientId,
                        'channel' => $data->channel,
                        'message' => $data->message,
                        'priority' => $data->priority,
                        'status' => NotificationStatus::Queued,
                        'deduplication_key' => $this->payloadHasher->deduplicationKey($data->idempotencyKey, $recipientId),
                    ])
                    ->all()
            );

            $topicCounts = $this->stageOutboxMessages($batch);

            $batch->loadCount('notifications');

            $responseBody = [
                'data' => (new NotificationBatchResource($batch))->resolve($request),
            ];

            IdempotencyKey::query()->updateOrCreate([
                'key' => $data->idempotencyKey,
            ], [
                'payload_hash' => $payloadHash,
                'notification_batch_id' => $batch->id,
                'response_body' => $responseBody,
                'response_status' => 201,
            ]);

            Log::info('Notification batch idempotency record stored.', [
                'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $payloadHash,
                'batch_id' => $batch->id,
            ]);

            Log::info('Notification batch created.', [
                'batch_id' => $batch->id,
                'notifications_count' => $batch->notifications_count,
            ]);

            Log::info('Notification outbox messages staged.', [
                'batch_id' => $batch->id,
                'notifications_count' => $batch->notifications_count,
                'topics' => $topicCounts,
            ]);

            return new NotificationBatchCreationResult($responseBody, 201);
        });

        if (! $result instanceof NotificationBatchCreationResult) {
            throw new LogicException('Notification batch transaction returned an unexpected result.');
        }

        return $result;
    }

    private function lockName(string $idempotencyKey): string
    {
        return 'notification-batches:idempotency:'.hash('sha256', $idempotencyKey);
    }

    /**
     * @return array<string, int>
     */
    private function stageOutboxMessages(NotificationBatch $batch): array
    {
        try {
            /** @var Collection<int, Notification> $notifications */
            $notifications = $batch->notifications()
                ->orderBy('id')
                ->get();

            $topicCounts = [];

            foreach ($notifications as $notification) {
                $topic = $this->topicResolver->topicFor($notification->priority);

                OutboxMessage::query()->create([
                    'aggregate_type' => Notification::class,
                    'aggregate_id' => $notification->id,
                    'topic' => $topic,
                    'payload' => $this->kafkaPayloadBuilder->forNotification($notification),
                    'status' => OutboxMessageStatus::Pending,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);

                $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
            }

            return $topicCounts;
        } catch (Throwable $exception) {
            Log::error('Notification outbox message staging failed.', [
                'batch_id' => $batch->id,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }
}
