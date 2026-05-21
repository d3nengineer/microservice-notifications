<?php

declare(strict_types=1);

namespace App\DTO;

class NotificationGatewayRateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $maxAttempts,
        public readonly int $decaySeconds,
        public readonly ?int $retryAfterSeconds = null,
    ) {}

    public static function allowed(int $maxAttempts, int $decaySeconds): self
    {
        return new self(
            allowed: true,
            maxAttempts: $maxAttempts,
            decaySeconds: $decaySeconds,
        );
    }

    public static function limited(int $maxAttempts, int $decaySeconds, int $retryAfterSeconds): self
    {
        return new self(
            allowed: false,
            maxAttempts: $maxAttempts,
            decaySeconds: $decaySeconds,
            retryAfterSeconds: $retryAfterSeconds,
        );
    }
}
