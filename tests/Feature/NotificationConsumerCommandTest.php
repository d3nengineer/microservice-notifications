<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaConsumer;
use App\DTO\GatewayResult;
use App\DTO\KafkaNotificationMessage;
use App\Enums\DeliveryAttemptStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\OutboxMessageStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaConsumer;
use App\Services\Notifications\Gateways\FakeGateway;
use App\Services\Notifications\NotificationGatewayRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class NotificationConsumerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_consume_command_processes_messages_in_priority_topic_order(): void
    {
        $gateway = $this->useFakeGateway();

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
        $this->assertSame([
            $highPriorityNotification->id,
            $lowPriorityNotification->id,
        ], $gateway->sentNotificationIds());
    }

    public function test_notifications_consume_command_counts_gateway_outcomes_as_consumed(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $gateway = $this->useFakeGateway();

        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $successNotification */
        $successNotification = Notification::factory()->create();
        /** @var Notification $temporaryFailureNotification */
        $temporaryFailureNotification = Notification::factory()->create();
        /** @var Notification $permanentFailureNotification */
        $permanentFailureNotification = Notification::factory()->create();

        $gateway->forNotification(
            $temporaryFailureNotification->id,
            GatewayResult::temporaryFailure('fake', 'timeout', 'Gateway timed out.'),
        );
        $gateway->forNotification(
            $permanentFailureNotification->id,
            GatewayResult::permanentFailure('fake', 'invalid_recipient', 'Recipient is invalid.'),
        );

        foreach ([$successNotification, $temporaryFailureNotification, $permanentFailureNotification] as $notification) {
            $consumer->push(new KafkaNotificationMessage(
                topic: 'notifications.high',
                payload: $this->validPayload($notification),
                key: 'notification:'.$notification->id,
            ));
        }

        $command = $this->artisan('notifications:consume', ['--limit' => 3, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 3 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(NotificationStatus::Sent, $successNotification->refresh()->status);
        $this->assertSame(NotificationStatus::Queued, $temporaryFailureNotification->refresh()->status);
        $this->assertSame(NotificationStatus::Dropped, $permanentFailureNotification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $successNotification->id,
            'status' => DeliveryAttemptStatus::Succeeded->value,
        ]);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $temporaryFailureNotification->id,
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
        ]);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $permanentFailureNotification->id,
            'status' => DeliveryAttemptStatus::PermanentlyFailed->value,
        ]);
        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $temporaryFailureNotification->id,
            'status' => OutboxMessageStatus::Pending->value,
        ]);
        $this->assertSame(2, OutboxMessage::query()->sole()->payload['attempt']);
    }

    public function test_notifications_consume_command_drops_exhausted_temporary_failures_without_retry(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $gateway = $this->useFakeGateway();
        $gateway->temporarilyFail(errorCode: 'timeout', errorMessage: 'Gateway timed out.');

        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($notification, attempt: 3),
            key: 'notification:'.$notification->id,
        ));

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(NotificationStatus::Dropped, $notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 3,
        ]);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
    }

    public function test_notifications_consume_command_reports_skip_missing_and_invalid_counts(): void
    {
        $gateway = $this->useFakeGateway();

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

        $this->assertSame(0, $gateway->sendCount($deliveredNotification->id));
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }

    public function test_notifications_consume_command_skips_duplicate_same_attempt_redelivery(): void
    {
        $gateway = $this->useFakeGateway();

        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        DeliveryAttempt::factory()->succeeded()->create([
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'attempt_number' => 3,
        ]);

        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($notification, attempt: 3),
            key: 'notification:'.$notification->id,
        ));

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 1);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
    }

    public function test_notifications_consume_command_counts_rate_limited_deliveries_as_consumed(): void
    {
        config()->set('notifications.cache.rate_limits.channels.email.max_attempts', 1);
        config()->set('notifications.cache.rate_limits.channels.email.decay_seconds', 60);
        config()->set('notifications.delivery.max_attempts', 3);

        $gateway = $this->useFakeGateway();

        $consumer = new FakeKafkaConsumer;
        $this->app->instance(KafkaConsumer::class, $consumer);

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        app(NotificationGatewayRateLimiter::class)->attempt($notification, 'FakeGateway');

        $consumer->push(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($notification),
            key: 'notification:'.$notification->id,
        ));

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'FakeGateway',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'error_code' => 'gateway_rate_limited',
        ]);
        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'status' => OutboxMessageStatus::Pending->value,
        ]);
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

    private function useFakeGateway(): FakeGateway
    {
        config()->set('notifications.gateways.channels.email', 'fake');
        config()->set('notifications.gateways.channels.sms', 'fake');

        $gateway = new FakeGateway;
        $this->app->instance(FakeGateway::class, $gateway);

        return $gateway;
    }
}
