<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\NotificationGatewayRateLimitResult;
use App\Models\Notification;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NotificationGatewayRateLimiter
{
    private const DEFAULT_STORE = 'redis';

    private const DEFAULT_PREFIX = 'notification-gateways';

    private const DEFAULT_MAX_ATTEMPTS = 60;

    private const DEFAULT_DECAY_SECONDS = 60;

    public function __construct(
        private readonly NotificationCacheSettings $settings,
    ) {}

    public function attempt(Notification $notification, string $gatewayName): NotificationGatewayRateLimitResult
    {
        $maxAttempts = $this->maxAttempts($notification);
        $decaySeconds = $this->decaySeconds($notification);
        $key = $this->key($notification, $gatewayName);
        $rateLimiter = $this->rateLimiter();

        if ($rateLimiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfterSeconds = $rateLimiter->availableIn($key);

            Log::warning('Notification gateway rate limit reached.', [
                'notification_id' => $notification->id,
                'channel' => $notification->channel->value,
                'gateway' => $gatewayName,
                'max_attempts' => $maxAttempts,
                'decay_seconds' => $decaySeconds,
                'retry_after_seconds' => $retryAfterSeconds,
            ]);

            return NotificationGatewayRateLimitResult::limited($maxAttempts, $decaySeconds, $retryAfterSeconds);
        }

        $rateLimiter->hit($key, $decaySeconds);

        Log::info('Notification gateway rate limit allowed delivery attempt.', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'gateway' => $gatewayName,
            'max_attempts' => $maxAttempts,
            'decay_seconds' => $decaySeconds,
        ]);

        return NotificationGatewayRateLimitResult::allowed($maxAttempts, $decaySeconds);
    }

    private function rateLimiter(): RateLimiter
    {
        return new RateLimiter(Cache::store($this->store()));
    }

    private function store(): string
    {
        return $this->settings->stringValue('notifications.cache.rate_limits.store', self::DEFAULT_STORE);
    }

    private function prefix(): string
    {
        return $this->settings->stringValue('notifications.cache.rate_limits.prefix', self::DEFAULT_PREFIX);
    }

    private function maxAttempts(Notification $notification): int
    {
        return $this->settings->positiveInteger(
            'notifications.cache.rate_limits.channels.'.$notification->channel->value.'.max_attempts',
            self::DEFAULT_MAX_ATTEMPTS,
        );
    }

    private function decaySeconds(Notification $notification): int
    {
        return $this->settings->positiveInteger(
            'notifications.cache.rate_limits.channels.'.$notification->channel->value.'.decay_seconds',
            self::DEFAULT_DECAY_SECONDS,
        );
    }

    private function key(Notification $notification, string $gatewayName): string
    {
        return implode(':', [
            $this->prefix(),
            $notification->channel->value,
            $gatewayName,
        ]);
    }
}
