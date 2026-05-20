<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaConsumer;
use App\DTO\KafkaNotificationMessage;
use App\Models\Notification;
use App\Services\Kafka\FakeKafkaConsumer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class NotificationConsumerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_consume_command_processes_messages_in_priority_topic_order(): void
    {
        config()->set('notifications.kafka.topics.high', 'custom.high');
        config()->set('notifications.kafka.topics.normal', 'custom.normal');
        config()->set('notifications.kafka.topics.low', 'custom.low');

        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $lowPriorityNotification */
        $lowPriorityNotification = Notification::factory()->create();
        /** @var Notification $highPriorityNotification */
        $highPriorityNotification = Notification::factory()->create();

        $consumer->push(new KafkaNotificationMessage(
            topic: 'custom.low',
            payload: $this->validPayload($lowPriorityNotification),
            key: 'notification:'.$lowPriorityNotification->id,
        ));
        $consumer->push(new KafkaNotificationMessage(
            topic: 'custom.high',
            payload: $this->validPayload($highPriorityNotification),
            key: 'notification:'.$highPriorityNotification->id,
        ));

        $command = $this->artisan('notifications:consume', ['--limit' => 2, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 2 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(['custom.high', 'custom.normal', 'custom.low'], $consumer->consumedTopics());
    }

    public function test_notifications_consume_command_reports_skip_missing_and_invalid_counts(): void
    {
        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $deliveredNotification */
        $deliveredNotification = Notification::factory()->delivered()->create();

        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($deliveredNotification),
        ));
        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: [
                'notification_id' => 999_999,
                'recipient_id' => 'subscriber-1',
                'channel' => 'email',
                'message' => 'Your verification code is 1234',
                'priority' => 'normal',
                'attempt' => 1,
            ],
        ));
        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: [
                'notification_id' => 'invalid',
                'attempt' => 1,
            ],
        ));

        $command = $this->artisan('notifications:consume', ['--limit' => 3, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 0 consumed, 1 skipped, 1 missing, 1 invalid.')
            ->assertSuccessful()
            ->run();
    }

    public function test_notifications_consume_command_succeeds_when_topics_are_empty(): void
    {
        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        $command = $this->artisan('notifications:consume', ['--limit' => 5, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 0 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(['notifications.high', 'notifications.normal', 'notifications.low'], $consumer->consumedTopics());
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
