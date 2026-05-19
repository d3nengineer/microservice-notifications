<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class IdempotencyLockTimeoutException extends RuntimeException
{
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $payloadHash,
    ) {
        parent::__construct('Another request is already processing this idempotency key.');
    }
}
