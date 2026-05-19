<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

readonly class CreateNotificationBatchDTO
{
    /**
     * @param  list<string>  $recipientIds
     */
    public function __construct(
        public string $idempotencyKey,
        public NotificationChannel $channel,
        public string $message,
        public array $recipientIds,
        public NotificationPriority $priority,
    ) {}

    /**
     * @param  array{idempotency_key: string, channel: string, message: string, recipient_ids: list<string>, priority: string}  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            idempotencyKey: $payload['idempotency_key'],
            channel: NotificationChannel::from($payload['channel']),
            message: $payload['message'],
            recipientIds: $payload['recipient_ids'],
            priority: NotificationPriority::from($payload['priority']),
        );
    }
}
