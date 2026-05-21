<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaProducer;
use App\Enums\OutboxMessageStatus;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OutboxPublisherCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function outbox_publish_command_publishes_due_messages(): void
    {
        $producer = new FakeKafkaProducer;
        $this->app->instance(KafkaProducer::class, $producer);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::factory()->create([
            'available_at' => now()->subMinute(),
        ]);

        $command = $this->artisan('outbox:publish', ['--limit' => 5, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 1 outbox messages: 1 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $this->assertSame(OutboxMessageStatus::Published, $message->refresh()->status);
        $this->assertCount(1, $producer->publishedMessages());
    }

    #[Test]
    public function outbox_publish_command_succeeds_when_no_messages_are_due(): void
    {
        $producer = new FakeKafkaProducer;
        $this->app->instance(KafkaProducer::class, $producer);

        $command = $this->artisan('outbox:publish', ['--limit' => 5, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 0 outbox messages: 0 published, 0 retried, 0 failed.')
            ->assertSuccessful()
            ->run();

        $this->assertCount(0, $producer->publishedMessages());
    }

    #[Test]
    public function outbox_publish_command_schedules_retry_when_publish_temporarily_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:00:00'));

        try {
            $producer = new FakeKafkaProducer;
            $producer->failWith(new RuntimeException('Kafka broker unavailable.'));
            $this->app->instance(KafkaProducer::class, $producer);

            /** @var OutboxMessage $message */
            $message = OutboxMessage::factory()->create([
                'available_at' => now()->subMinute(),
            ]);

            $command = $this->artisan('outbox:publish', ['--limit' => 5, '--max-attempts' => 3, '--once' => true]);
            $this->assertInstanceOf(PendingCommand::class, $command);

            $command->expectsOutputToContain('Processed 1 outbox messages: 0 published, 1 retried, 0 failed.')
                ->assertSuccessful()
                ->run();

            $message->refresh();

            $this->assertSame(OutboxMessageStatus::Pending, $message->status);
            $this->assertSame(1, $message->attempts);
            $this->assertSame('Kafka broker unavailable.', $message->last_error);
            $this->assertTrue($message->available_at->greaterThan(now()));
            $this->assertNull($message->published_at);
            $this->assertCount(0, $producer->publishedMessages());
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function outbox_publish_command_marks_message_failed_when_max_attempts_are_exhausted(): void
    {
        $producer = new FakeKafkaProducer;
        $producer->failWith(new RuntimeException('Kafka broker unavailable.'));
        $this->app->instance(KafkaProducer::class, $producer);

        /** @var OutboxMessage $message */
        $message = OutboxMessage::factory()->create([
            'attempts' => 2,
            'available_at' => now()->subMinute(),
        ]);

        $command = $this->artisan('outbox:publish', ['--limit' => 5, '--max-attempts' => 3, '--once' => true]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command->expectsOutputToContain('Processed 1 outbox messages: 0 published, 0 retried, 1 failed.')
            ->assertSuccessful()
            ->run();

        $message->refresh();

        $this->assertSame(OutboxMessageStatus::Failed, $message->status);
        $this->assertSame(3, $message->attempts);
        $this->assertSame('Kafka broker unavailable.', $message->last_error);
        $this->assertNull($message->published_at);
        $this->assertCount(0, $producer->publishedMessages());
    }
}
