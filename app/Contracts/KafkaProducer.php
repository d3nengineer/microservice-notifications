<?php

declare(strict_types=1);

namespace App\Contracts;

interface KafkaProducer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $topic, array $payload, ?string $key = null): void;
}
