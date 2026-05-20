<?php

declare(strict_types=1);

namespace App\DTO;

class KafkaNotificationMessage
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $topic,
        public readonly array $payload,
        public readonly ?string $key = null,
        public readonly array $metadata = [],
    ) {}
}
