<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_local_user_authentication_is_not_configured(): void
    {
        $this->assertNull(config('auth.defaults.guard'));
        $this->assertNull(config('auth.defaults.passwords'));
        $this->assertNull(config('auth.guards.web.provider'));
        $this->assertNull(config('auth.providers.users.model'));
        $this->assertNull(config('auth.passwords.users.provider'));
        $this->assertNull(config('auth.passwords.users.table'));
    }
}
