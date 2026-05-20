<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PublishOutboxMessages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('outbox:publish
    {--limit= : Maximum number of due messages to publish}
    {--max-attempts= : Attempts before a message is marked failed}
    {--once : Process one bounded batch and exit}')]
#[Description('Publish due notification outbox messages.')]
class PublishOutboxMessagesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PublishOutboxMessages $publishOutboxMessages): int
    {
        $limit = $this->integerOption('limit') ?? $this->integerConfig('notifications.kafka.publisher.limit', 100);
        $maxAttempts = $this->integerOption('max-attempts') ?? $this->integerConfig('notifications.kafka.publisher.max_attempts', 3);
        $backoffSeconds = $this->integerConfig('notifications.kafka.publisher.backoff_seconds', 60);

        Log::info('Outbox publish command started.', [
            'limit' => $limit,
            'max_attempts' => $maxAttempts,
            'once' => (bool) $this->option('once'),
        ]);

        try {
            $result = $publishOutboxMessages(
                limit: $limit,
                maxAttempts: $maxAttempts,
                backoffSeconds: $backoffSeconds,
            );
        } catch (Throwable $exception) {
            Log::error('Outbox publish command failed.', [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $this->components->info(sprintf(
            'Processed %d outbox messages: %d published, %d retried, %d failed.',
            $result->processed,
            $result->published,
            $result->retried,
            $result->failed,
        ));

        Log::info('Outbox publish command completed.', $result->toArray());

        return self::SUCCESS;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === false || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}
