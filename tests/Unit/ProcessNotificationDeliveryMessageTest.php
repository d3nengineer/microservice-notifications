<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\ProcessNotificationDeliveryMessage;
use App\DTO\KafkaNotificationMessage;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Enums\NotificationStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessNotificationDeliveryMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_queued_notifications_for_gateway_handoff(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                'notification_id' => $notification->id,
                'attempt' => 1,
            ],
            key: 'notification:'.$notification->id,
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(NotificationDeliveryProcessingStatus::Consumed, $result->status);
        $this->assertSame($notification->id, $result->notificationId);
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }

    public function test_it_skips_notifications_in_final_statuses(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->delivered()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: [
                'notification_id' => $notification->id,
                'attempt' => 2,
            ],
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertSame($notification->id, $result->notificationId);
        $this->assertSame('final_status', $result->reason);
    }

    public function test_it_reports_missing_notifications(): void
    {
        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.low',
            payload: [
                'notification_id' => 999_999,
                'attempt' => 1,
            ],
        ));

        $this->assertTrue($result->isMissing());
        $this->assertSame(999_999, $result->notificationId);
        $this->assertSame('notification_missing', $result->reason);
    }

    public function test_it_reports_malformed_payloads(): void
    {
        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                'notification_id' => 'not-an-integer',
                'attempt' => 1,
            ],
            metadata: ['partition' => 1],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->notificationId);
        $this->assertSame('missing_notification_id', $result->reason);
    }

    public function test_it_skips_dropped_notifications_without_delivery_attempts(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'status' => NotificationStatus::Dropped,
        ]);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                'notification_id' => $notification->id,
                'attempt' => 3,
            ],
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }
}
