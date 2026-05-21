<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaConsumer;
use App\Contracts\KafkaProducer;
use App\DTO\GatewayResult;
use App\DTO\KafkaNotificationMessage;
use App\Enums\DeliveryAttemptStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Enums\OutboxMessageStatus;
use App\Models\DeliveryAttempt;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaConsumer;
use App\Services\Kafka\FakeKafkaProducer;
use App\Services\Notifications\Gateways\FakeGateway;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class NotificationIntegrationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mass_notification_intake_stages_and_publishes_outbox_messages(): void
    {
        $producer = $this->useFakeMessaging()->producer;
        $gateway = $this->useFakeGateway();

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1', 'subscriber-2', 'subscriber-3'],
            'priority' => NotificationPriority::High->value,
        ], [
            'Idempotency-Key' => 'integration-intake-001',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.notifications_count', 3)
            ->assertJsonPath('data.status', NotificationStatus::Queued->value);

        $batchId = $response->json('data.batch_id');

        $this->assertDatabaseHas((new NotificationBatch)->getTable(), [
            'id' => $batchId,
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::High->value,
            'idempotency_key' => 'integration-intake-001',
            'status' => NotificationStatus::Queued->value,
        ]);
        $this->assertDatabaseCount((new NotificationBatch)->getTable(), 1);
        $this->assertDatabaseCount((new Notification)->getTable(), 3);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 3);

        /** @var Collection<int, Notification> $notifications */
        $notifications = Notification::query()
            ->where('batch_id', $batchId)
            ->orderBy('recipient_id')
            ->get();

        /** @var Collection<int, OutboxMessage> $outboxMessages */
        $outboxMessages = OutboxMessage::query()
            ->orderBy('aggregate_id')
            ->get();

        $this->assertSame(
            $notifications->pluck('id')->sort()->values()->all(),
            $outboxMessages->pluck('aggregate_id')->sort()->values()->all()
        );

        foreach ($outboxMessages as $outboxMessage) {
            $payload = $this->notificationPayload($outboxMessage->payload);

            $this->assertSame(Notification::class, $outboxMessage->aggregate_type);
            $this->assertSame('notifications.high', $outboxMessage->topic);
            $this->assertSame(OutboxMessageStatus::Pending, $outboxMessage->status);
            $this->assertSame(0, $outboxMessage->attempts);
            $this->assertSame(1, $payload['attempt']);
            $this->assertSame(NotificationChannel::Email->value, $payload['channel']);
            $this->assertSame(NotificationPriority::High->value, $payload['priority']);
        }

        $command = $this->artisan('outbox:publish', ['--limit' => 10, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 3 outbox messages: 3 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $this->assertDatabaseMissing((new OutboxMessage)->getTable(), [
            'status' => OutboxMessageStatus::Pending->value,
        ]);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 3);
        $this->assertCount(3, $producer->publishedMessages());

        foreach ($producer->publishedMessages() as $publishedMessage) {
            $payload = $this->notificationPayload($publishedMessage['payload']);

            $this->assertSame('notifications.high', $publishedMessage['topic']);
            $this->assertSame(1, $payload['attempt']);
            $this->assertSame(NotificationChannel::Email->value, $payload['channel']);
            $this->assertSame(NotificationPriority::High->value, $payload['priority']);
            $this->assertContains($payload['notification_id'], $notifications->pluck('id')->all());
            $this->assertSame(Notification::class.':'.$payload['notification_id'], $publishedMessage['key']);
        }

        $this->assertSame(0, $notifications->sum(fn (Notification $notification): int => $gateway->sendCount($notification->id)));
    }

    public function test_idempotent_intake_replays_identical_request_and_rejects_conflict_without_duplicate_publish(): void
    {
        $producer = $this->useFakeMessaging()->producer;
        $this->useFakeGateway();

        $payload = [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1', 'subscriber-2'],
            'priority' => NotificationPriority::High->value,
        ];
        $headers = ['Idempotency-Key' => 'integration-idempotency-001'];

        $firstResponse = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);
        $secondResponse = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);
        $conflictResponse = $this->postJson(route('api.v1.notification-batches.store'), [
            ...$payload,
            'message' => 'Your verification code is 5678',
        ], $headers);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();
        $conflictResponse
            ->assertConflict()
            ->assertJsonPath('error', 'idempotency_key_conflict');

        $this->assertSame($firstResponse->json(), $secondResponse->json());
        $this->assertDatabaseCount((new NotificationBatch)->getTable(), 1);
        $this->assertDatabaseCount((new Notification)->getTable(), 2);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 2);
        $this->assertDatabaseCount((new IdempotencyKey)->getTable(), 1);

        $command = $this->artisan('outbox:publish', ['--limit' => 10, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 2 outbox messages: 2 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $this->assertCount(2, $producer->publishedMessages());
    }

    public function test_published_notification_is_consumed_successfully_and_visible_in_subscriber_history(): void
    {
        $fixtures = $this->createPublishedNotification(
            idempotencyKey: 'integration-consumer-success-001',
            recipientId: 'subscriber-success',
        );

        $this->pushPublishedMessage($fixtures->consumer, $fixtures->producer->publishedMessages()[0]);

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(1, $fixtures->gateway->sendCount($fixtures->notification->id));
        $this->assertSame(NotificationStatus::Sent, $fixtures->notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $fixtures->notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::Succeeded->value,
            'attempt_number' => 1,
            'error_code' => null,
            'error_message' => null,
        ]);

        $historyResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-success',
        ]));

        $historyResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixtures->notification->id)
            ->assertJsonPath('data.0.status', NotificationStatus::Sent->value);
    }

    public function test_temporary_gateway_failure_stages_retry_and_retry_can_succeed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00'));
        config()->set('notifications.delivery.max_attempts', 3);
        config()->set('notifications.delivery.backoff_seconds', 1);
        config()->set('notifications.delivery.max_backoff_seconds', 10);

        $fixtures = $this->createPublishedNotification(
            idempotencyKey: 'integration-consumer-retry-001',
            recipientId: 'subscriber-retry',
        );

        $fixtures->gateway->forNotification(
            $fixtures->notification->id,
            GatewayResult::temporaryFailure('fake', 'timeout', 'Gateway timed out.'),
        );

        $this->pushPublishedMessage($fixtures->consumer, $fixtures->producer->publishedMessages()[0]);

        $firstConsumeCommand = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $firstConsumeCommand);

        $firstConsumeCommand->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(1, $fixtures->gateway->sendCount($fixtures->notification->id));
        $this->assertSame(NotificationStatus::Queued, $fixtures->notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $fixtures->notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 1,
            'error_code' => 'timeout',
            'error_message' => 'Gateway timed out.',
        ]);

        /** @var OutboxMessage $retryOutboxMessage */
        $retryOutboxMessage = OutboxMessage::query()
            ->where('aggregate_id', $fixtures->notification->id)
            ->where('status', OutboxMessageStatus::Pending)
            ->sole();

        $this->assertSame(2, $retryOutboxMessage->payload['attempt']);
        $this->assertTrue($retryOutboxMessage->available_at->equalTo(Carbon::parse('2026-05-21 10:00:01')));

        $queuedHistoryResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-retry',
        ]));

        $queuedHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixtures->notification->id)
            ->assertJsonPath('data.0.status', NotificationStatus::Queued->value);

        $fixtures->gateway->forNotification(
            $fixtures->notification->id,
            GatewayResult::success('fake'),
        );
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:02'));

        $publishRetryCommand = $this->artisan('outbox:publish', ['--limit' => 10, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $publishRetryCommand);

        $publishRetryCommand->expectsOutputToContain('Processed 1 outbox messages: 1 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $publishedMessages = $fixtures->producer->publishedMessages();
        $this->assertCount(2, $publishedMessages);
        $this->pushPublishedMessage($fixtures->consumer, $publishedMessages[1]);

        $secondConsumeCommand = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $secondConsumeCommand);

        $secondConsumeCommand->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(2, $fixtures->gateway->sendCount($fixtures->notification->id));
        $this->assertSame(NotificationStatus::Sent, $fixtures->notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $fixtures->notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::Succeeded->value,
            'attempt_number' => 2,
            'error_code' => null,
            'error_message' => null,
        ]);
        $this->assertSame(0, OutboxMessage::query()
            ->where('aggregate_id', $fixtures->notification->id)
            ->where('status', OutboxMessageStatus::Pending)
            ->count());

        $sentHistoryResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-retry',
        ]));

        $sentHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixtures->notification->id)
            ->assertJsonPath('data.0.status', NotificationStatus::Sent->value);
    }

    public function test_permanent_gateway_failure_drops_notification_without_retry(): void
    {
        $fixtures = $this->createPublishedNotification(
            idempotencyKey: 'integration-consumer-permanent-failure-001',
            recipientId: 'subscriber-permanent-failure',
        );

        $fixtures->gateway->permanentlyFail(errorCode: 'invalid_recipient', errorMessage: 'Recipient is invalid.');
        $this->pushPublishedMessage($fixtures->consumer, $fixtures->producer->publishedMessages()[0]);

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(1, $fixtures->gateway->sendCount($fixtures->notification->id));
        $this->assertSame(NotificationStatus::Dropped, $fixtures->notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $fixtures->notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::PermanentlyFailed->value,
            'attempt_number' => 1,
            'error_code' => 'invalid_recipient',
            'error_message' => 'Recipient is invalid.',
        ]);
        $this->assertSame(0, OutboxMessage::query()
            ->where('aggregate_id', $fixtures->notification->id)
            ->where('status', OutboxMessageStatus::Pending)
            ->count());
    }

    public function test_exhausted_temporary_gateway_failure_drops_notification_without_retry(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $fixtures = $this->createPublishedNotification(
            idempotencyKey: 'integration-consumer-exhausted-failure-001',
            recipientId: 'subscriber-exhausted-failure',
        );

        $fixtures->gateway->temporarilyFail(errorCode: 'timeout', errorMessage: 'Gateway timed out.');

        $publishedMessage = $fixtures->producer->publishedMessages()[0];
        $publishedMessage['payload']['attempt'] = 3;
        $this->pushPublishedMessage($fixtures->consumer, $publishedMessage);

        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(1, $fixtures->gateway->sendCount($fixtures->notification->id));
        $this->assertSame(NotificationStatus::Dropped, $fixtures->notification->refresh()->status);
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $fixtures->notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 3,
            'error_code' => 'timeout',
            'error_message' => 'Gateway timed out.',
        ]);
        $this->assertSame(0, OutboxMessage::query()
            ->where('aggregate_id', $fixtures->notification->id)
            ->where('status', OutboxMessageStatus::Pending)
            ->count());
    }

    public function test_subscriber_history_reflects_current_statuses_and_invalidates_cached_responses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 11:00:00'));
        config()->set('notifications.cache.history.enabled', true);
        config()->set('notifications.cache.history.store', 'array');
        config()->set('notifications.delivery.max_attempts', 3);
        config()->set('notifications.delivery.backoff_seconds', 1);
        config()->set('notifications.delivery.max_backoff_seconds', 10);

        $messaging = $this->useFakeMessaging();
        $gateway = $this->useFakeGateway();
        $recipientId = 'subscriber-history';

        $successfulNotification = $this->createPublishedNotificationUsing(
            producer: $messaging->producer,
            idempotencyKey: 'integration-history-success-001',
            recipientId: $recipientId,
        );
        Notification::factory()->create([
            'recipient_id' => 'subscriber-history-other',
            'status' => NotificationStatus::Sent,
        ]);

        $cachedQueuedHistoryResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => $recipientId,
        ]));

        $cachedQueuedHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $successfulNotification->id)
            ->assertJsonPath('data.0.status', NotificationStatus::Queued->value);

        $this->pushPublishedMessage($messaging->consumer, $this->lastPublishedMessage($messaging->producer));
        $this->consumeOneNotification();

        $freshSentHistoryResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => $recipientId,
        ]));

        $freshSentHistoryResponse
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $successfulNotification->id)
            ->assertJsonPath('data.0.status', NotificationStatus::Sent->value);

        $retrySuccessNotification = $this->createPublishedNotificationUsing(
            producer: $messaging->producer,
            idempotencyKey: 'integration-history-retry-success-001',
            recipientId: $recipientId,
        );
        $gateway->forNotification(
            $retrySuccessNotification->id,
            GatewayResult::temporaryFailure('fake', 'timeout', 'Gateway timed out.'),
        );
        $this->pushPublishedMessage($messaging->consumer, $this->lastPublishedMessage($messaging->producer));
        $this->consumeOneNotification();
        $gateway->forNotification($retrySuccessNotification->id, GatewayResult::success('fake'));
        Carbon::setTestNow(Carbon::parse('2026-05-21 11:00:02'));
        $this->publishOneOutboxMessage();
        $this->pushPublishedMessage($messaging->consumer, $this->lastPublishedMessage($messaging->producer));
        $this->consumeOneNotification();

        $droppedNotification = $this->createPublishedNotificationUsing(
            producer: $messaging->producer,
            idempotencyKey: 'integration-history-dropped-001',
            recipientId: $recipientId,
        );
        $gateway->forNotification(
            $droppedNotification->id,
            GatewayResult::permanentFailure('fake', 'invalid_recipient', 'Recipient is invalid.'),
        );
        $this->pushPublishedMessage($messaging->consumer, $this->lastPublishedMessage($messaging->producer));
        $this->consumeOneNotification();

        $queuedRetryNotification = $this->createPublishedNotificationUsing(
            producer: $messaging->producer,
            idempotencyKey: 'integration-history-queued-retry-001',
            recipientId: $recipientId,
        );
        $gateway->forNotification(
            $queuedRetryNotification->id,
            GatewayResult::temporaryFailure('fake', 'timeout', 'Gateway timed out.'),
        );
        $this->pushPublishedMessage($messaging->consumer, $this->lastPublishedMessage($messaging->producer));
        $this->consumeOneNotification();

        $historyResponse = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => $recipientId,
        ]));

        $historyResponse
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.total', 4);

        $historyNotifications = $this->historyNotifications($historyResponse->json('data'));

        $statusesByNotificationId = collect($historyNotifications)
            ->mapWithKeys(fn (array $notification): array => [$notification['id'] => $notification['status']])
            ->all();

        $this->assertSame(NotificationStatus::Sent->value, $statusesByNotificationId[$successfulNotification->id]);
        $this->assertSame(NotificationStatus::Sent->value, $statusesByNotificationId[$retrySuccessNotification->id]);
        $this->assertSame(NotificationStatus::Dropped->value, $statusesByNotificationId[$droppedNotification->id]);
        $this->assertSame(NotificationStatus::Queued->value, $statusesByNotificationId[$queuedRetryNotification->id]);
        $this->assertNotContains('subscriber-history-other', collect($historyNotifications)->pluck('recipient_id')->all());
    }

    /**
     * @return object{
     *     producer: FakeKafkaProducer,
     *     consumer: FakeKafkaConsumer,
     *     gateway: FakeGateway,
     *     notification: Notification
     * }
     */
    private function createPublishedNotification(string $idempotencyKey, string $recipientId): object
    {
        $messaging = $this->useFakeMessaging();
        $gateway = $this->useFakeGateway();

        $notification = $this->createPublishedNotificationUsing(
            producer: $messaging->producer,
            idempotencyKey: $idempotencyKey,
            recipientId: $recipientId,
        );

        return (object) [
            'producer' => $messaging->producer,
            'consumer' => $messaging->consumer,
            'gateway' => $gateway,
            'notification' => $notification,
        ];
    }

    private function createPublishedNotificationUsing(
        FakeKafkaProducer $producer,
        string $idempotencyKey,
        string $recipientId,
    ): Notification {
        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => [$recipientId],
            'priority' => NotificationPriority::High->value,
        ], [
            'Idempotency-Key' => $idempotencyKey,
        ]);

        $response->assertCreated();

        $command = $this->artisan('outbox:publish', ['--limit' => 10, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 1 outbox messages: 1 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $batchId = $response->json('data.batch_id');

        /** @var Notification $notification */
        $notification = Notification::query()
            ->where('batch_id', $batchId)
            ->where('recipient_id', $recipientId)
            ->sole();

        $this->assertSame($notification->id, $this->notificationPayload($this->lastPublishedMessage($producer)['payload'])['notification_id']);

        return $notification;
    }

    /**
     * @param  array{topic: string, payload: array<string, mixed>, key: string|null}  $publishedMessage
     */
    private function pushPublishedMessage(FakeKafkaConsumer $consumer, array $publishedMessage): void
    {
        $consumer->push(new KafkaNotificationMessage(
            topic: $publishedMessage['topic'],
            payload: $publishedMessage['payload'],
            key: $publishedMessage['key'],
        ));
    }

    private function consumeOneNotification(): void
    {
        $command = $this->artisan('notifications:consume', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Consumed notifications: 1 consumed, 0 skipped, 0 missing, 0 invalid.')
            ->assertSuccessful()
            ->run();
    }

    private function publishOneOutboxMessage(): void
    {
        $command = $this->artisan('outbox:publish', ['--limit' => 1, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 1 outbox messages: 1 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();
    }

    /**
     * @return array{topic: string, payload: array<string, mixed>, key: string|null}
     */
    private function lastPublishedMessage(FakeKafkaProducer $producer): array
    {
        $publishedMessages = $producer->publishedMessages();
        $this->assertNotEmpty($publishedMessages);

        $lastPublishedMessageKey = array_key_last($publishedMessages);
        $this->assertIsInt($lastPublishedMessageKey);

        return $publishedMessages[$lastPublishedMessageKey];
    }

    /**
     * @return array{notification_id: int, channel: string, priority: string, attempt: int}
     */
    private function notificationPayload(mixed $payload): array
    {
        $this->assertIsArray($payload);

        $notificationId = $payload['notification_id'] ?? null;
        $channel = $payload['channel'] ?? null;
        $priority = $payload['priority'] ?? null;
        $attempt = $payload['attempt'] ?? null;

        $this->assertIsInt($notificationId);
        $this->assertIsString($channel);
        $this->assertIsString($priority);
        $this->assertIsInt($attempt);

        return [
            'notification_id' => $notificationId,
            'channel' => $channel,
            'priority' => $priority,
            'attempt' => $attempt,
        ];
    }

    /**
     * @return array<int, array{id: int, recipient_id: string, status: string}>
     */
    private function historyNotifications(mixed $notifications): array
    {
        $this->assertIsArray($notifications);

        return array_values(array_map(function (mixed $notification): array {
            $this->assertIsArray($notification);

            $id = $notification['id'] ?? null;
            $recipientId = $notification['recipient_id'] ?? null;
            $status = $notification['status'] ?? null;

            $this->assertIsInt($id);
            $this->assertIsString($recipientId);
            $this->assertIsString($status);

            return [
                'id' => $id,
                'recipient_id' => $recipientId,
                'status' => $status,
            ];
        }, $notifications));
    }

    /**
     * @return object{producer: FakeKafkaProducer, consumer: FakeKafkaConsumer}
     */
    private function useFakeMessaging(): object
    {
        $producer = new FakeKafkaProducer;
        $consumer = new FakeKafkaConsumer;

        $this->app->instance(KafkaProducer::class, $producer);
        $this->app->instance(KafkaConsumer::class, $consumer);

        return (object) [
            'producer' => $producer,
            'consumer' => $consumer,
        ];
    }

    private function useFakeGateway(): FakeGateway
    {
        config()->set('notifications.gateways.channels.email', 'fake');
        config()->set('notifications.gateways.channels.sms', 'fake');
        config()->set('notifications.cache.rate_limits.store', 'array');

        $gateway = new FakeGateway;
        $this->app->instance(FakeGateway::class, $gateway);

        return $gateway;
    }
}
