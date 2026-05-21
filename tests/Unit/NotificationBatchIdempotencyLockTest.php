<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\NotificationBatchIdempotencyLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationBatchIdempotencyLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_callback_inside_configured_lock(): void
    {
        config()->set('notifications.cache.locks.store', 'array');
        config()->set('notifications.cache.locks.ttl_seconds', 5);
        config()->set('notifications.cache.locks.block_seconds', 1);

        $result = app(NotificationBatchIdempotencyLock::class)->block(
            idempotencyKey: 'request-001',
            payloadHash: 'payload-hash',
            callback: fn (): string => 'created',
        );

        $this->assertSame('created', $result);
    }

    public function test_it_builds_stable_hashed_lock_names(): void
    {
        $lock = app(NotificationBatchIdempotencyLock::class);

        $this->assertSame(
            'notification-batches:idempotency:'.hash('sha256', 'request-001'),
            $lock->lockName('request-001'),
        );
    }
}
