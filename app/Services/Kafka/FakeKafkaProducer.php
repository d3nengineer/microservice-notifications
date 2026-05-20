<?php

declare(strict_types=1);

namespace App\Services\Kafka;

use App\Contracts\KafkaProducer;
use RuntimeException;
use Throwable;

class FakeKafkaProducer implements KafkaProducer
{
    /**
     * @var array<int, array{topic: string, payload: array<string, mixed>, key: string|null}>
     */
    private array $publishedMessages = [];

    private ?Throwable $exception = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $topic, array $payload, ?string $key = null): void
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        $this->publishedMessages[] = [
            'topic' => $topic,
            'payload' => $payload,
            'key' => $key,
        ];
    }

    public function failWith(?Throwable $exception = null): void
    {
        $this->exception = $exception ?? new RuntimeException('Fake Kafka producer failure.');
    }

    /**
     * @return array<int, array{topic: string, payload: array<string, mixed>, key: string|null}>
     */
    public function publishedMessages(): array
    {
        return $this->publishedMessages;
    }
}
