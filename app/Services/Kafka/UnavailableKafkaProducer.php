<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use App\Contracts\KafkaProducer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class UnavailableKafkaProducer implements KafkaProducer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $topic, array $payload, ?string $key = null): void
    {
        Log::error('Kafka producer is unavailable.', [
            'topic' => $topic,
            'payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'key' => $key,
        ]);

        throw new RuntimeException('Kafka producer is unavailable. Configure a real notification Kafka producer adapter.');
    }
}
