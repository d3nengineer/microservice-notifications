<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Notifications\NotificationGatewayRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationGatewayRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allows_attempts_until_the_channel_limit_is_reached(): void
    {
        config()->set('notifications.cache.rate_limits.store', 'array');
        config()->set('notifications.cache.rate_limits.channels.email.max_attempts', 1);
        config()->set('notifications.cache.rate_limits.channels.email.decay_seconds', 60);

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);
        $rateLimiter = app(NotificationGatewayRateLimiter::class);

        $first = $rateLimiter->attempt($notification, 'FakeGateway');
        $second = $rateLimiter->attempt($notification, 'FakeGateway');

        $this->assertTrue($first->allowed);
        $this->assertFalse($second->allowed);
        $this->assertSame(1, $second->maxAttempts);
        $this->assertSame(60, $second->decaySeconds);
        $this->assertNotNull($second->retryAfterSeconds);
    }

    public function test_invalid_channel_limit_config_falls_back_to_safe_defaults(): void
    {
        config()->set('notifications.cache.rate_limits.store', 'array');
        config()->set('notifications.cache.rate_limits.channels.email.max_attempts', 0);
        config()->set('notifications.cache.rate_limits.channels.email.decay_seconds', -5);

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        $result = app(NotificationGatewayRateLimiter::class)->attempt($notification, 'FakeGateway');

        $this->assertTrue($result->allowed);
        $this->assertSame(60, $result->maxAttempts);
        $this->assertSame(60, $result->decaySeconds);
    }
}
