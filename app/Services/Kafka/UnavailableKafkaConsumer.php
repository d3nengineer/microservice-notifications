<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use App\Contracts\KafkaConsumer;
use App\DTO\KafkaNotificationMessage;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UnavailableKafkaConsumer implements KafkaConsumer
{
    /**
     * @return iterable<int, KafkaNotificationMessage>
     */
    public function consume(string $topic, int $limit): iterable
    {
        Log::error('Kafka consumer is unavailable.', [
            'topic' => $topic,
            'limit' => $limit,
        ]);

        throw new RuntimeException('Kafka consumer is unavailable. Configure a real notification Kafka consumer adapter.');
    }
}
