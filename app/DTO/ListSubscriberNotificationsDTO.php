<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;

readonly class ListSubscriberNotificationsDTO
{
    public function __construct(
        public string $recipientId,
        public ?NotificationStatus $status,
        public ?NotificationChannel $channel,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * @param  array{status?: string, channel?: string, page?: int, per_page?: int}  $filters
     */
    public static function fromArray(string $recipientId, array $filters, int $perPage): self
    {
        return new self(
            recipientId: $recipientId,
            status: isset($filters['status']) ? NotificationStatus::from($filters['status']) : null,
            channel: isset($filters['channel']) ? NotificationChannel::from($filters['channel']) : null,
            page: (int) ($filters['page'] ?? 1),
            perPage: $perPage,
        );
    }
}
