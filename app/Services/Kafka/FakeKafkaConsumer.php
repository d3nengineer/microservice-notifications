<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use App\Contracts\KafkaConsumer;
use App\DTO\KafkaNotificationMessage;

class FakeKafkaConsumer implements KafkaConsumer
{
    /**
     * @var array<string, array<int, KafkaNotificationMessage>>
     */
    private array $messagesByTopic = [];

    /**
     * @var array<int, string>
     */
    private array $consumedTopics = [];

    public function push(KafkaNotificationMessage $message): void
    {
        $this->messagesByTopic[$message->topic][] = $message;
    }

    /**
     * @return iterable<int, KafkaNotificationMessage>
     */
    public function consume(string $topic, int $limit): iterable
    {
        $this->consumedTopics[] = $topic;

        $messages = $this->messagesByTopic[$topic] ?? [];
        $consumedMessages = array_splice($messages, 0, $limit);
        $this->messagesByTopic[$topic] = $messages;

        return $consumedMessages;
    }

    /**
     * @return array<int, string>
     */
    public function consumedTopics(): array
    {
        return $this->consumedTopics;
    }
}
