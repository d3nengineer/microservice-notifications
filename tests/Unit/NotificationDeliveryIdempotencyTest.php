<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationDeliveryIdempotencyTest extends TestCase
{
    /**
     * @return array<string, array{status: NotificationStatus}>
     */
    public static function sendableStatusProvider(): array
    {
        return [
            'queued' => ['status' => NotificationStatus::Queued],
        ];
    }

    #[DataProvider('sendableStatusProvider')]
    public function test_queued_notification_can_be_sent(NotificationStatus $status): void
    {
        $notification = new Notification([
            'status' => $status,
            'deduplication_key' => 'dedupe-1',
        ]);

        $this->assertTrue($notification->canBeSent());
    }

    /**
     * @return array<string, array{status: NotificationStatus}>
     */
    public static function finalStatusProvider(): array
    {
        return [
            'sent' => ['status' => NotificationStatus::Sent],
            'delivered' => ['status' => NotificationStatus::Delivered],
            'dropped' => ['status' => NotificationStatus::Dropped],
        ];
    }

    #[DataProvider('finalStatusProvider')]
    public function test_final_status_notification_cannot_be_sent(NotificationStatus $status): void
    {
        $notification = new Notification([
            'id' => 123,
            'status' => $status,
            'deduplication_key' => 'dedupe-1',
        ]);

        $this->assertFalse($notification->canBeSent());
    }
}
