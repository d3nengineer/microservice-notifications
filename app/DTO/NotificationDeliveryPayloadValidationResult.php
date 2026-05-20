<?php

declare(strict_types=1);

namespace App\DTO;

use LogicException;

class NotificationDeliveryPayloadValidationResult
{
    /**
     * @param  array{
     *     notification_id: int,
     *     recipient_id: string,
     *     channel: string,
     *     message: string,
     *     priority: string,
     *     attempt: int
     * }|null  $payload
     * @param  array<int, string>  $invalidFields
     */
    public function __construct(
        public readonly bool $valid,
        public readonly ?array $payload = null,
        public readonly array $invalidFields = [],
        public readonly ?string $reason = null,
    ) {}

    public function isInvalid(): bool
    {
        return ! $this->valid;
    }

    /**
     * @return array{
     *     notification_id: int,
     *     recipient_id: string,
     *     channel: string,
     *     message: string,
     *     priority: string,
     *     attempt: int
     * }
     */
    public function payload(): array
    {
        if ($this->payload === null) {
            throw new LogicException('Notification delivery payload is not available for an invalid validation result.');
        }

        return $this->payload;
    }
}
