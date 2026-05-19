<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\NotificationPriority;
use InvalidArgumentException;

class NotificationKafkaTopicResolver
{
    public function topicFor(NotificationPriority $priority): string
    {
        $topic = config("notifications.kafka.topics.{$priority->value}");

        if (! is_string($topic) || $topic === '') {
            throw new InvalidArgumentException("Kafka topic is not configured for priority [{$priority->value}].");
        }

        return $topic;
    }

    /**
     * @return array<int, string>
     */
    public function priorityOrder(): array
    {
        return [
            $this->topicFor(NotificationPriority::High),
            $this->topicFor(NotificationPriority::Normal),
            $this->topicFor(NotificationPriority::Low),
        ];
    }
}
