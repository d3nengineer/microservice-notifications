<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('notifications.cache.locks.store', 'array');
        config()->set('notifications.cache.rate_limits.store', 'array');
        config()->set('notifications.cache.history.store', 'array');
        config()->set('notifications.cache.history.enabled', false);
    }
}
