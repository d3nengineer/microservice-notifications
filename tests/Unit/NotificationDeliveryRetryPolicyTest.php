<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Notifications\NotificationDeliveryRetryPolicy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class NotificationDeliveryRetryPolicyTest extends TestCase
{
    public function test_it_retries_attempts_below_the_max_attempts(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertFalse($policy->isExhausted(1));
    }

    public function test_it_exhausts_attempts_at_the_max_attempts(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertFalse($policy->shouldRetry(3));
        $this->assertTrue($policy->isExhausted(3));
    }

    public function test_it_computes_exponential_backoff(): void
    {
        config()->set('notifications.delivery.backoff_seconds', 60);
        config()->set('notifications.delivery.max_backoff_seconds', 900);

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertSame(60, $policy->delaySecondsForAttempt(1));
        $this->assertSame(120, $policy->delaySecondsForAttempt(2));
        $this->assertSame(240, $policy->delaySecondsForAttempt(3));
    }

    public function test_it_caps_exponential_backoff(): void
    {
        config()->set('notifications.delivery.backoff_seconds', 60);
        config()->set('notifications.delivery.max_backoff_seconds', 180);

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertSame(180, $policy->delaySecondsForAttempt(4));
    }

    public function test_it_falls_back_to_safe_values_for_invalid_config(): void
    {
        config()->set('notifications.delivery.max_attempts', 0);
        config()->set('notifications.delivery.backoff_seconds', -5);
        config()->set('notifications.delivery.max_backoff_seconds', 10);

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertSame(3, $policy->maxAttempts());
        $this->assertSame(60, $policy->delaySecondsForAttempt(1));
    }

    public function test_retry_decisions_do_not_emit_duplicate_operational_logs(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);
        Log::spy();

        $policy = new NotificationDeliveryRetryPolicy;

        $this->assertTrue($policy->shouldRetry(1));
        $this->assertFalse($policy->isExhausted(1));
        $this->assertFalse($policy->shouldRetry(3));
        $this->assertTrue($policy->isExhausted(3));
        Log::shouldNotHaveReceived('info');
    }
}
