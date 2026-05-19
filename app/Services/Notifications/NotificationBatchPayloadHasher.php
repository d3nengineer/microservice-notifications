<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\CreateNotificationBatchDTO;

class NotificationBatchPayloadHasher
{
    public function payloadHash(CreateNotificationBatchDTO $data): string
    {
        return hash('sha256', json_encode([
            'channel' => $data->channel->value,
            'message' => $data->message,
            'priority' => $data->priority->value,
            'recipient_ids' => $data->recipientIds,
        ], JSON_THROW_ON_ERROR));
    }

    public function deduplicationKey(string $idempotencyKey, string $recipientId): string
    {
        return hash('sha256', $idempotencyKey.'|'.$recipientId);
    }
}
