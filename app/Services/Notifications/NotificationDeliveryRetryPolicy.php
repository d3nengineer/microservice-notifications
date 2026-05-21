<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

class NotificationDeliveryRetryPolicy
{
    private const DEFAULT_MAX_ATTEMPTS = 3;

    private const DEFAULT_BACKOFF_SECONDS = 60;

    private const DEFAULT_MAX_BACKOFF_SECONDS = 900;

    public function shouldRetry(int $currentAttempt): bool
    {
        return ! $this->isExhausted($currentAttempt);
    }

    public function isExhausted(int $currentAttempt): bool
    {
        return $currentAttempt >= $this->maxAttempts();
    }

    public function delaySecondsForAttempt(int $currentAttempt): int
    {
        $settings = $this->settings();
        $exponent = max(0, $currentAttempt - 1);
        $delaySeconds = min(
            $settings['backoff_seconds'] * (2 ** $exponent),
            $settings['max_backoff_seconds'],
        );

        return $delaySeconds;
    }

    public function maxAttempts(): int
    {
        return $this->settings()['max_attempts'];
    }

    /**
     * @return array{max_attempts: int, backoff_seconds: int, max_backoff_seconds: int}
     */
    private function settings(): array
    {
        $maxAttempts = $this->positiveInteger(
            key: 'notifications.delivery.max_attempts',
            fallback: self::DEFAULT_MAX_ATTEMPTS,
        );
        $backoffSeconds = $this->positiveInteger(
            key: 'notifications.delivery.backoff_seconds',
            fallback: self::DEFAULT_BACKOFF_SECONDS,
        );
        $maxBackoffSeconds = $this->positiveInteger(
            key: 'notifications.delivery.max_backoff_seconds',
            fallback: self::DEFAULT_MAX_BACKOFF_SECONDS,
        );

        if ($maxBackoffSeconds < $backoffSeconds) {
            Log::error('Notification delivery retry config is invalid; using safe fallback.', [
                'config_key' => 'notifications.delivery.max_backoff_seconds',
                'configured_value' => $maxBackoffSeconds,
                'fallback_value' => $backoffSeconds,
            ]);

            $maxBackoffSeconds = $backoffSeconds;
        }

        return [
            'max_attempts' => $maxAttempts,
            'backoff_seconds' => $backoffSeconds,
            'max_backoff_seconds' => $maxBackoffSeconds,
        ];
    }

    private function positiveInteger(string $key, int $fallback): int
    {
        $value = config($key);

        if (is_numeric($value) && (int) $value >= 1) {
            return (int) $value;
        }

        Log::error('Notification delivery retry config is invalid; using safe fallback.', [
            'config_key' => $key,
            'configured_value' => $value,
            'fallback_value' => $fallback,
        ]);

        return $fallback;
    }
}
