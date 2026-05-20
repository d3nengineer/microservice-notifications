<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_health_check_returns_a_successful_response(): void
    {
        $this->get('/up')->assertOk();
    }
}
