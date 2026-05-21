<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\ListSubscriberNotificationsDTO;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubscriberNotificationHistoryCache
{
    private const DEFAULT_STORE = 'redis';

    private const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private readonly NotificationCacheSettings $settings,
    ) {}

    /**
     * @template T of LengthAwarePaginator
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function remember(ListSubscriberNotificationsDTO $filters, Closure $callback): LengthAwarePaginator
    {
        if (! $this->enabled()) {
            return $callback();
        }

        $key = $this->key($filters);
        $store = $this->store();

        try {
            if (Cache::store($store)->has($key)) {
                Log::info('Subscriber notification history cache hit.', [
                    'recipient_id' => $filters->recipientId,
                    'status' => $filters->status?->value,
                    'channel' => $filters->channel?->value,
                    'page' => $filters->page,
                    'per_page' => $filters->perPage,
                ]);
            } else {
                Log::info('Subscriber notification history cache miss.', [
                    'recipient_id' => $filters->recipientId,
                    'status' => $filters->status?->value,
                    'channel' => $filters->channel?->value,
                    'page' => $filters->page,
                    'per_page' => $filters->perPage,
                ]);
            }

            /** @var T $paginator */
            $paginator = Cache::store($store)->remember($key, $this->ttlSeconds(), $callback);

            return $paginator;
        } catch (Throwable $exception) {
            Log::warning('Subscriber notification history cache failed; falling back to database.', [
                'recipient_id' => $filters->recipientId,
                'exception_class' => $exception::class,
            ]);

            return $callback();
        }
    }

    public function invalidate(string $recipientId, string $reason): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $store = Cache::store($this->store());
            $store->add($this->versionKey($recipientId), 0);
            $store->increment($this->versionKey($recipientId));
        } catch (Throwable $exception) {
            Log::warning('Subscriber notification history cache invalidation failed.', [
                'recipient_id' => $recipientId,
                'reason' => $reason,
                'exception_class' => $exception::class,
            ]);

            return;
        }

        Log::info('Subscriber notification history cache invalidated.', [
            'recipient_id' => $recipientId,
            'reason' => $reason,
        ]);
    }

    public function key(ListSubscriberNotificationsDTO $filters): string
    {
        $parts = [
            'recipient_id' => $filters->recipientId,
            'status' => $filters->status?->value,
            'channel' => $filters->channel?->value,
            'page' => $filters->page,
            'per_page' => $filters->perPage,
            'version' => $this->version($filters->recipientId),
        ];

        return 'subscribers:notifications:'.sha1((string) json_encode($parts));
    }

    public function enabled(): bool
    {
        return (bool) config('notifications.cache.history.enabled', false);
    }

    private function version(string $recipientId): int
    {
        try {
            $version = Cache::store($this->store())->get($this->versionKey($recipientId), 0);

            if (is_numeric($version)) {
                return (int) $version;
            }

            return 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function versionKey(string $recipientId): string
    {
        return 'subscribers:notifications:version:'.sha1($recipientId);
    }

    private function store(): string
    {
        return $this->settings->stringValue('notifications.cache.history.store', self::DEFAULT_STORE);
    }

    private function ttlSeconds(): int
    {
        return $this->settings->positiveInteger('notifications.cache.history.ttl_seconds', self::DEFAULT_TTL_SECONDS);
    }
}
