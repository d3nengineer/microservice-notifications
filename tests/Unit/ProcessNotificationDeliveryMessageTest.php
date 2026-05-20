<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\ProcessNotificationDeliveryMessage;
use App\DTO\KafkaNotificationMessage;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            payload: $this->validPayload($notification),
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
            payload: $this->validPayload($notification, attempt: 2),
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
                'recipient_id' => 'subscriber-1',
                'channel' => NotificationChannel::Email->value,
                'message' => 'Your verification code is 1234',
                'priority' => NotificationPriority::Normal->value,
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
        $this->assertSame('malformed_payload', $result->reason);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    #[DataProvider('malformedPayloadProvider')]
    public function test_it_reports_malformed_payload_fields(array $payloadOverrides): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                ...$this->validPayload($notification),
                ...$payloadOverrides,
            ],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->notificationId);
        $this->assertSame('malformed_payload', $result->reason);
    }

    public function test_it_skips_dropped_notifications_without_delivery_attempts(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'status' => NotificationStatus::Dropped,
        ]);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification, attempt: 3),
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }

    /**
     * @return array<string, array{payloadOverrides: array<string, mixed>}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'invalid channel' => [
                'payloadOverrides' => ['channel' => 'push'],
            ],
            'missing message' => [
                'payloadOverrides' => ['message' => null],
            ],
            'invalid priority' => [
                'payloadOverrides' => ['priority' => 'urgent'],
            ],
            'invalid attempt' => [
                'payloadOverrides' => ['attempt' => 0],
            ],
        ];
    }

    /**
     * @return array{
     *     notification_id: int,
     *     recipient_id: string,
     *     channel: string,
     *     message: string,
     *     priority: string,
     *     attempt: int
     * }
     */
    private function validPayload(Notification $notification, int $attempt = 1): array
    {
        return [
            'notification_id' => $notification->id,
            'recipient_id' => $notification->recipient_id,
            'channel' => $notification->channel->value,
            'message' => $notification->message,
            'priority' => $notification->priority->value,
            'attempt' => $attempt,
        ];
    }
}
