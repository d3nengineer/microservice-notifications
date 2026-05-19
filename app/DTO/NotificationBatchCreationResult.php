<?php

declare(strict_types=1);

namespace App\DTO;

readonly class NotificationBatchCreationResult
{
    /**
     * @param  array<string, mixed>  $responseBody
     */
    public function __construct(
        public array $responseBody,
        public int $responseStatus,
    ) {}
}
