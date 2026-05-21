<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaConsumer;
use App\Contracts\KafkaProducer;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Enums\OutboxMessageStatus;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaConsumer;
use App\Services\Kafka\FakeKafkaProducer;
use App\Services\Notifications\Gateways\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class NotificationIntegrationFlowTest extends TestCase
{
    use RefreshDatabase;

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

        $notifications = Notification::query()
            ->where('batch_id', $batchId)
            ->orderBy('recipient_id')
            ->get();

        $outboxMessages = OutboxMessage::query()
            ->orderBy('aggregate_id')
            ->get();

        $this->assertSame(
            $notifications->pluck('id')->sort()->values()->all(),
            $outboxMessages->pluck('aggregate_id')->sort()->values()->all()
        );

        foreach ($outboxMessages as $outboxMessage) {
            $this->assertSame(Notification::class, $outboxMessage->aggregate_type);
            $this->assertSame('notifications.high', $outboxMessage->topic);
            $this->assertSame(OutboxMessageStatus::Pending, $outboxMessage->status);
            $this->assertSame(0, $outboxMessage->attempts);
            $this->assertSame(1, $outboxMessage->payload['attempt']);
            $this->assertSame(NotificationChannel::Email->value, $outboxMessage->payload['channel']);
            $this->assertSame(NotificationPriority::High->value, $outboxMessage->payload['priority']);
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
            $this->assertSame('notifications.high', $publishedMessage['topic']);
            $this->assertSame(1, $publishedMessage['payload']['attempt']);
            $this->assertSame(NotificationChannel::Email->value, $publishedMessage['payload']['channel']);
            $this->assertSame(NotificationPriority::High->value, $publishedMessage['payload']['priority']);
            $this->assertContains($publishedMessage['payload']['notification_id'], $notifications->pluck('id')->all());
            $this->assertSame(Notification::class.':'.$publishedMessage['payload']['notification_id'], $publishedMessage['key']);
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

        $gateway = new FakeGateway;
        $this->app->instance(FakeGateway::class, $gateway);

        return $gateway;
    }
}
