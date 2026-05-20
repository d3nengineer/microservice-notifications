<?php

declare(strict_types=1);

namespace App\DTO;

class NotificationConsumeResult
{
    public function __construct(
        public readonly int $consumed,
        public readonly int $skipped,
        public readonly int $missing,
        public readonly int $invalid,
    ) {}

    /**
     * @return array{consumed: int, skipped: int, missing: int, invalid: int}
     */
    public function toArray(): array
    {
        return [
            'consumed' => $this->consumed,
            'skipped' => $this->skipped,
            'missing' => $this->missing,
            'invalid' => $this->invalid,
        ];
    }
}
