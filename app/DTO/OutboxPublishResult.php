<?php

declare(strict_types=1);

namespace App\DTO;

class OutboxPublishResult
{
    public function __construct(
        public readonly int $processed,
        public readonly int $published,
        public readonly int $retried,
        public readonly int $failed,
    ) {}

    /**
     * @return array{processed: int, published: int, retried: int, failed: int}
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'published' => $this->published,
            'retried' => $this->retried,
            'failed' => $this->failed,
        ];
    }
}
