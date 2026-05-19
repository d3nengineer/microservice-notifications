<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTO\CreateNotificationBatchDTO;
use App\Enums\NotificationStatus;
use App\Models\NotificationBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateNotificationBatch
{
    public function __invoke(CreateNotificationBatchDTO $data): NotificationBatch
    {
        Log::info('Notification batch creation started.', [
            'idempotency_key' => $data->idempotencyKey,
            'channel' => $data->channel->value,
            'priority' => $data->priority->value,
            'recipient_count' => count($data->recipientIds),
        ]);

        $batch = new NotificationBatch;

        DB::transaction(function () use ($data, &$batch): void {
            $batch = NotificationBatch::create([
                'channel' => $data->channel,
                'message' => $data->message,
                'priority' => $data->priority,
                'idempotency_key' => $data->idempotencyKey,
                'payload_hash' => $this->payloadHash($data),
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
                        'deduplication_key' => $this->deduplicationKey($data->idempotencyKey, $recipientId),
                    ])
                    ->all()
            );

            $batch->loadCount('notifications');
        });

        Log::info('Notification batch created.', [
            'batch_id' => $batch->id,
            'notifications_count' => $batch->notifications_count,
        ]);

        return $batch;
    }

    private function payloadHash(CreateNotificationBatchDTO $data): string
    {
        return hash('sha256', json_encode([
            'channel' => $data->channel->value,
            'message' => $data->message,
            'priority' => $data->priority->value,
            'recipient_ids' => $data->recipientIds,
        ], JSON_THROW_ON_ERROR));
    }

    private function deduplicationKey(string $idempotencyKey, string $recipientId): string
    {
        return hash('sha256', $idempotencyKey.'|'.$recipientId);
    }
}
