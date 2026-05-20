<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ConsumeNotificationDeliveryMessages;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('notifications:consume
    {--limit=10 : Maximum number of messages to process across all priority topics}
    {--once : Process one bounded pass and exit}')]
#[Description('Consume notification delivery messages from Kafka priority topics.')]
class ConsumeNotificationsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ConsumeNotificationDeliveryMessages $consumeMessages): int
    {
        $limit = max(0, (int) $this->option('limit'));

        Log::info('Notification consume command started.', [
            'limit' => $limit,
            'once' => (bool) $this->option('once'),
        ]);

        try {
            $result = $consumeMessages(limit: $limit);
        } catch (Throwable $exception) {
            Log::error('Notification consume command failed.', [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $this->components->info(sprintf(
            'Consumed notifications: %d consumed, %d skipped, %d missing, %d invalid.',
            $result->consumed,
            $result->skipped,
            $result->missing,
            $result->invalid,
        ));

        Log::info('Notification consume command completed.', $result->toArray());

        return self::SUCCESS;
    }
}
