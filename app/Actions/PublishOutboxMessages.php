<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\KafkaProducer;
use App\DTO\OutboxPublishResult;
use App\Enums\OutboxMessageStatus;
use App\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishOutboxMessages
{
    public function __construct(
        private readonly KafkaProducer $producer,
    ) {}

    public function __invoke(?int $limit = null, ?int $maxAttempts = null, ?int $backoffSeconds = null): OutboxPublishResult
    {
        $limit ??= $this->integerConfig('notifications.kafka.publisher.limit', 100);
        $maxAttempts ??= $this->integerConfig('notifications.kafka.publisher.max_attempts', 3);
        $backoffSeconds ??= $this->integerConfig('notifications.kafka.publisher.backoff_seconds', 60);

        Log::info('Outbox publish batch started.', [
            'limit' => $limit,
            'max_attempts' => $maxAttempts,
            'backoff_seconds' => $backoffSeconds,
        ]);

        $processed = 0;
        $published = 0;
        $retried = 0;
        $failed = 0;

        /** @var Collection<int, OutboxMessage> $messages */
        $messages = OutboxMessage::query()
            ->where('status', OutboxMessageStatus::Pending)
            ->where('available_at', '<=', now())
            ->orderBy('available_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($messages as $message) {
            $processed++;

            try {
                $this->producer->publish(
                    topic: $message->topic,
                    payload: $message->payload,
                    key: "{$message->aggregate_type}:{$message->aggregate_id}",
                );

                $message->forceFill([
                    'status' => OutboxMessageStatus::Published,
                    'published_at' => now(),
                    'last_error' => null,
                ])->save();

                $published++;

                Log::info('Outbox message published.', [
                    'outbox_message_id' => $message->id,
                    'topic' => $message->topic,
                    'status' => OutboxMessageStatus::Published->value,
                    'attempts' => $message->attempts,
                ]);
            } catch (Throwable $exception) {
                $newAttempts = $message->attempts + 1;

                if ($newAttempts >= $maxAttempts) {
                    $message->forceFill([
                        'status' => OutboxMessageStatus::Failed,
                        'attempts' => $newAttempts,
                        'last_error' => $exception->getMessage(),
                    ])->save();

                    $failed++;

                    Log::error('Outbox message marked failed.', [
                        'outbox_message_id' => $message->id,
                        'topic' => $message->topic,
                        'status' => OutboxMessageStatus::Failed->value,
                        'attempts' => $newAttempts,
                        'exception' => $exception::class,
                    ]);

                    continue;
                }

                $message->forceFill([
                    'attempts' => $newAttempts,
                    'available_at' => now()->addSeconds($backoffSeconds),
                    'last_error' => $exception->getMessage(),
                ])->save();

                $retried++;

                Log::warning('Outbox message retry scheduled.', [
                    'outbox_message_id' => $message->id,
                    'topic' => $message->topic,
                    'status' => $message->status->value,
                    'attempts' => $newAttempts,
                    'backoff_seconds' => $backoffSeconds,
                    'exception' => $exception::class,
                ]);
            }
        }

        $result = new OutboxPublishResult($processed, $published, $retried, $failed);

        Log::info('Outbox publish batch completed.', $result->toArray());

        return $result;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
