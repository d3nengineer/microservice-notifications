<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\NotificationDeliveryProcessingStatus;

class NotificationDeliveryProcessingResult
{
    public function __construct(
        public readonly NotificationDeliveryProcessingStatus $status,
        public readonly ?int $notificationId = null,
        public readonly ?string $reason = null,
    ) {}

    public function isConsumed(): bool
    {
        return $this->status === NotificationDeliveryProcessingStatus::Consumed;
    }

    public function isSkipped(): bool
    {
        return $this->status === NotificationDeliveryProcessingStatus::Skipped;
    }

    public function isMissing(): bool
    {
        return $this->status === NotificationDeliveryProcessingStatus::Missing;
    }

    public function isInvalid(): bool
    {
        return $this->status === NotificationDeliveryProcessingStatus::Invalid;
    }
}
