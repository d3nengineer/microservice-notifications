<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_service_migrations_do_not_create_local_user_tables(): void
    {
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
        $this->assertFalse(Schema::hasTable('sessions'));
    }

    public function test_notifications_use_external_recipient_identifiers_without_user_relationships(): void
    {
        $this->assertTrue(Schema::hasColumn('notifications', 'recipient_id'));
        $this->assertSame('varchar', Schema::getColumnType('notifications', 'recipient_id'));

        $this->assertFalse(Schema::hasColumn('notifications', 'user_id'));
    }
}
