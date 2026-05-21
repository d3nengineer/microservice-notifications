<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\ListSubscriberNotificationsDTO;
use App\Services\Notifications\SubscriberNotificationHistoryCache;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

class SubscriberNotificationHistoryCacheTest extends TestCase
{
    public function test_it_bypasses_cache_when_history_cache_is_disabled(): void
    {
        config()->set('notifications.cache.history.enabled', false);

        $calls = 0;
        $cache = app(SubscriberNotificationHistoryCache::class);
        $filters = $this->filters();

        $cache->remember($filters, function () use (&$calls): LengthAwarePaginator {
            $calls++;

            return new LengthAwarePaginator(['first'], 1, 15);
        });
        $cache->remember($filters, function () use (&$calls): LengthAwarePaginator {
            $calls++;

            return new LengthAwarePaginator(['second'], 1, 15);
        });

        $this->assertSame(2, $calls);
    }

    public function test_it_caches_history_until_recipient_history_is_invalidated(): void
    {
        config()->set('notifications.cache.history.enabled', true);
        config()->set('notifications.cache.history.store', 'array');
        config()->set('notifications.cache.history.ttl_seconds', 60);

        $calls = 0;
        $cache = app(SubscriberNotificationHistoryCache::class);
        $filters = $this->filters();

        $first = $cache->remember($filters, function () use (&$calls): LengthAwarePaginator {
            $calls++;

            return new LengthAwarePaginator(['first'], 1, 15);
        });
        $second = $cache->remember($filters, function () use (&$calls): LengthAwarePaginator {
            $calls++;

            return new LengthAwarePaginator(['second'], 1, 15);
        });

        $cache->invalidate('subscriber-1', 'test');

        $third = $cache->remember($filters, function () use (&$calls): LengthAwarePaginator {
            $calls++;

            return new LengthAwarePaginator(['third'], 1, 15);
        });

        $this->assertSame(2, $calls);
        $this->assertSame(['first'], $first->items());
        $this->assertSame(['first'], $second->items());
        $this->assertSame(['third'], $third->items());
    }

    private function filters(): ListSubscriberNotificationsDTO
    {
        return new ListSubscriberNotificationsDTO(
            recipientId: 'subscriber-1',
            status: null,
            channel: null,
            page: 1,
            perPage: 15,
        );
    }
}
