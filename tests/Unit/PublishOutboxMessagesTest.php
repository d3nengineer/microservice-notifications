<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\PublishOutboxMessages;
use App\Contracts\KafkaProducer;
use App\Enums\OutboxMessageStatus;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PublishOutboxMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_due_pending_messages(): void
    {
        $producer = new FakeKafkaProducer;
        $this->app->instance(KafkaProducer::class, $producer);

        /** @var Notification $notification */
        $notification = Notification::factory()->create();
        /** @var OutboxMessage $message */
        $message = OutboxMessage::factory()->forNotification($notification)->create();

        $result = app(PublishOutboxMessages::class)(limit: 10, maxAttempts: 3, backoffSeconds: 60);

        $message->refresh();

        $this->assertSame(1, $result->processed);
        $this->assertSame(1, $result->published);
        $this->assertSame(0, $result->retried);
        $this->assertSame(0, $result->failed);
        $this->assertSame(OutboxMessageStatus::Published, $message->status);
        $this->assertNotNull($message->published_at);
        $this->assertCount(1, $producer->publishedMessages());
        $this->assertSame($message->topic, $producer->publishedMessages()[0]['topic']);
        $this->assertSame(Notification::class.':'.$notification->id, $producer->publishedMessages()[0]['key']);
    }

    public function test_it_schedules_retry_when_publish_fails_before_attempts_are_exhausted(): void
    {
        $producer = new FakeKafkaProducer;
        $producer->failWith(new RuntimeException('Temporary outage.'));
        $this->app->instance(KafkaProducer::class, $producer);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::factory()->create([
            'attempts' => 1,
            'available_at' => now()->subMinute(),
        ]);

        $result = app(PublishOutboxMessages::class)(limit: 10, maxAttempts: 3, backoffSeconds: 120);

        $message->refresh();

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->retried);
        $this->assertSame(0, $result->failed);
        $this->assertSame(OutboxMessageStatus::Pending, $message->status);
        $this->assertSame(2, $message->attempts);
        $this->assertTrue($message->available_at->greaterThan(now()->addSeconds(90)));
        $this->assertSame('Temporary outage.', $message->last_error);
    }

    public function test_it_marks_message_failed_when_attempts_are_exhausted(): void
    {
        $producer = new FakeKafkaProducer;
        $producer->failWith(new RuntimeException('Permanent outage.'));
        $this->app->instance(KafkaProducer::class, $producer);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::factory()->create([
            'attempts' => 2,
            'available_at' => now()->subMinute(),
        ]);

        $result = app(PublishOutboxMessages::class)(limit: 10, maxAttempts: 3, backoffSeconds: 120);

        $message->refresh();

        $this->assertSame(1, $result->processed);
        $this->assertSame(0, $result->published);
        $this->assertSame(0, $result->retried);
        $this->assertSame(1, $result->failed);
        $this->assertSame(OutboxMessageStatus::Failed, $message->status);
        $this->assertSame(3, $message->attempts);
        $this->assertSame('Permanent outage.', $message->last_error);
    }

    public function test_it_ignores_unavailable_and_already_published_messages(): void
    {
        $producer = new FakeKafkaProducer;
        $this->app->instance(KafkaProducer::class, $producer);

        OutboxMessage::factory()->create(['available_at' => now()->addHour()]);
        OutboxMessage::factory()->published()->create(['available_at' => now()->subMinute()]);

        $result = app(PublishOutboxMessages::class)(limit: 10, maxAttempts: 3, backoffSeconds: 60);

        $this->assertSame(0, $result->processed);
        $this->assertCount(0, $producer->publishedMessages());
    }
}
