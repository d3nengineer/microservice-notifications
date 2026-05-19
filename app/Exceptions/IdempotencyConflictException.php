<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $payloadHash,
    ) {
        parent::__construct('The idempotency key has already been used with a different payload.');
    }
}
