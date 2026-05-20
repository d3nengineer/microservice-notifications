<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\KafkaConsumer;
use App\DTO\NotificationConsumeResult;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Services\Notifications\NotificationKafkaTopicResolver;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsumeNotificationDeliveryMessages
{
    public function __construct(
        private readonly KafkaConsumer $consumer,
        private readonly NotificationKafkaTopicResolver $topicResolver,
        private readonly ProcessNotificationDeliveryMessage $processMessage,
    ) {}

    public function __invoke(int $limit): NotificationConsumeResult
    {
        $limit = max(0, $limit);
        $topics = $this->topicResolver->priorityOrder();

        Log::info('Notification consume batch started.', [
            'topics' => $topics,
            'limit' => $limit,
        ]);

        $remaining = $limit;
        $consumed = 0;
        $skipped = 0;
        $missing = 0;
        $invalid = 0;

        foreach ($topics as $topic) {
            if ($remaining <= 0) {
                break;
            }

            foreach ($this->consumer->consume($topic, $remaining) as $message) {
                try {
                    Log::info('Notification delivery message received.', [
                        'topic' => $message->topic,
                        'notification_id' => $message->payload['notification_id'] ?? null,
                        'key' => $message->key,
                    ]);

                    $result = ($this->processMessage)($message);
                } catch (Throwable $exception) {
                    Log::error('Notification delivery message handler failed.', [
                        'topic' => $message->topic,
                        'key' => $message->key,
                        'exception' => $exception::class,
                    ]);

                    throw $exception;
                }

                match ($result->status) {
                    NotificationDeliveryProcessingStatus::Consumed => $consumed++,
                    NotificationDeliveryProcessingStatus::Skipped => $skipped++,
                    NotificationDeliveryProcessingStatus::Missing => $missing++,
                    NotificationDeliveryProcessingStatus::Invalid => $invalid++,
                };

                $remaining--;

                if ($remaining <= 0) {
                    break;
                }
            }
        }

        $result = new NotificationConsumeResult($consumed, $skipped, $missing, $invalid);

        Log::info('Notification consume batch completed.', $result->toArray());

        return $result;
    }
}
