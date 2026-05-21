<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Log;

class NotificationCacheSettings
{
    public function positiveInteger(string $key, int $fallback): int
    {
        $value = config($key);

        if (is_numeric($value) && (int) $value >= 1) {
            return (int) $value;
        }

        Log::error('Notification cache config is invalid; using safe fallback.', [
            'config_key' => $key,
            'configured_value' => $value,
            'fallback_value' => $fallback,
        ]);

        return $fallback;
    }

    public function stringValue(string $key, string $fallback): string
    {
        $value = config($key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        Log::error('Notification cache config is invalid; using safe fallback.', [
            'config_key' => $key,
            'configured_value' => $value,
            'fallback_value' => $fallback,
        ]);

        return $fallback;
    }
}
