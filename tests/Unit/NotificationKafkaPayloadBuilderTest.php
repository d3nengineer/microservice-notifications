<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Models\Notification;
use App\Services\Notifications\NotificationKafkaPayloadBuilder;
use App\Services\Notifications\NotificationKafkaTopicResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationKafkaPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_deterministic_notification_delivery_payloads(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Sms,
            'message' => 'Your verification code is 1234',
            'priority' => NotificationPriority::Low,
        ]);

        $payload = app(NotificationKafkaPayloadBuilder::class)->forNotification($notification, attempt: 2);

        $this->assertSame([
            'notification_id' => $notification->id,
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Sms->value,
            'message' => 'Your verification code is 1234',
            'priority' => NotificationPriority::Low->value,
            'attempt' => 2,
        ], $payload);
    }

    public function test_it_resolves_priority_topics_from_configuration(): void
    {
        config()->set('notifications.kafka.topics.high', 'custom.high');
        config()->set('notifications.kafka.topics.normal', 'custom.normal');
        config()->set('notifications.kafka.topics.low', 'custom.low');

        $resolver = app(NotificationKafkaTopicResolver::class);

        $this->assertSame('custom.high', $resolver->topicFor(NotificationPriority::High));
        $this->assertSame('custom.normal', $resolver->topicFor(NotificationPriority::Normal));
        $this->assertSame('custom.low', $resolver->topicFor(NotificationPriority::Low));
        $this->assertSame(['custom.high', 'custom.normal', 'custom.low'], $resolver->priorityOrder());
    }
}
