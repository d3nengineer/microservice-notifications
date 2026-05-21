<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationBatchIdempotencyLock
{
    private const DEFAULT_STORE = 'redis';

    private const DEFAULT_TTL_SECONDS = 10;

    private const DEFAULT_BLOCK_SECONDS = 3;

    public function __construct(
        private readonly NotificationCacheSettings $settings,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function block(string $idempotencyKey, string $payloadHash, Closure $callback): mixed
    {
        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $lockName = $this->lockName($idempotencyKey);
        $store = $this->store();
        $ttlSeconds = $this->ttlSeconds();
        $blockSeconds = $this->blockSeconds();

        Log::info('Notification batch idempotency lock acquisition started.', [
            'idempotency_key_hash' => $idempotencyKeyHash,
            'payload_hash' => $payloadHash,
            'store' => $store,
            'ttl_seconds' => $ttlSeconds,
            'block_seconds' => $blockSeconds,
        ]);

        try {
            /** @var Repository $repository */
            $repository = Cache::store($store);

            /** @phpstan-ignore-next-line Laravel cache repositories support atomic locks at runtime. */
            $lock = $repository->lock($lockName, $ttlSeconds);
            /** @var Lock $lock */

            return $lock->block($blockSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            Log::warning('Notification batch idempotency lock acquisition timed out.', [
                'idempotency_key_hash' => $idempotencyKeyHash,
                'payload_hash' => $payloadHash,
                'store' => $store,
                'block_seconds' => $blockSeconds,
            ]);

            throw $exception;
        } finally {
            Log::info('Notification batch idempotency lock acquisition finished.', [
                'idempotency_key_hash' => $idempotencyKeyHash,
                'payload_hash' => $payloadHash,
                'store' => $store,
            ]);
        }
    }

    public function lockName(string $idempotencyKey): string
    {
        return 'notification-batches:idempotency:'.hash('sha256', $idempotencyKey);
    }

    private function store(): string
    {
        return $this->settings->stringValue('notifications.cache.locks.store', self::DEFAULT_STORE);
    }

    private function ttlSeconds(): int
    {
        return $this->settings->positiveInteger('notifications.cache.locks.ttl_seconds', self::DEFAULT_TTL_SECONDS);
    }

    private function blockSeconds(): int
    {
        return $this->settings->positiveInteger('notifications.cache.locks.block_seconds', self::DEFAULT_BLOCK_SECONDS);
    }
}
