<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\KafkaProducer;
use App\Enums\OutboxMessageStatus;
use App\Models\OutboxMessage;
use App\Services\Kafka\FakeKafkaProducer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
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
}
