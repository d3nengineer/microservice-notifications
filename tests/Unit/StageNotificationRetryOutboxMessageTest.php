<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationPriority;
use App\Enums\OutboxMessageStatus;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Services\Notifications\NotificationDeliveryRetryPolicy;
use App\Services\Notifications\StageNotificationRetryOutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StageNotificationRetryOutboxMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_stages_a_pending_retry_outbox_message_for_the_next_attempt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));

        /** @var Notification $notification */
        $notification = Notification::factory()->highPriority()->create();

        $outboxMessage = app(StageNotificationRetryOutboxMessage::class)(
            notification: $notification,
            currentAttempt: 1,
            delaySeconds: 120,
        );

        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'id' => $outboxMessage->id,
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'topic' => 'notifications.high',
            'status' => OutboxMessageStatus::Pending->value,
            'attempts' => 0,
            'published_at' => null,
            'last_error' => null,
        ]);

        $this->assertSame([
            'notification_id' => $notification->id,
            'recipient_id' => $notification->recipient_id,
            'channel' => $notification->channel->value,
            'message' => $notification->message,
            'priority' => NotificationPriority::High->value,
            'attempt' => 2,
        ], $outboxMessage->payload);
        $this->assertTrue($outboxMessage->available_at->equalTo(Carbon::parse('2026-05-20 10:02:00')));
    }

    public function test_it_uses_the_supplied_clock_for_delayed_availability(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->create();
        $now = Carbon::parse('2026-05-20 12:30:00');

        $outboxMessage = app(StageNotificationRetryOutboxMessage::class)(
            notification: $notification,
            currentAttempt: 2,
            delaySeconds: 900,
            now: $now,
        );

        $this->assertSame('notifications.normal', $outboxMessage->topic);
        $this->assertSame(3, $outboxMessage->payload['attempt']);
        $this->assertTrue($outboxMessage->available_at->equalTo(Carbon::parse('2026-05-20 12:45:00')));
    }

    public function test_it_stages_retry_with_capped_policy_delay_and_priority_topic(): void
    {
        config()->set('notifications.delivery.backoff_seconds', 60);
        config()->set('notifications.delivery.max_backoff_seconds', 180);

        /** @var Notification $notification */
        $notification = Notification::factory()->highPriority()->create();
        $now = Carbon::parse('2026-05-20 14:00:00');
        $delaySeconds = app(NotificationDeliveryRetryPolicy::class)->delaySecondsForAttempt(4);

        $outboxMessage = app(StageNotificationRetryOutboxMessage::class)(
            notification: $notification,
            currentAttempt: 4,
            delaySeconds: $delaySeconds,
            now: $now,
        );

        $this->assertSame('notifications.high', $outboxMessage->topic);
        $this->assertSame(5, $outboxMessage->payload['attempt']);
        $this->assertTrue($outboxMessage->available_at->equalTo(Carbon::parse('2026-05-20 14:03:00')));
    }
}
