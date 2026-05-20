<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\KafkaNotificationMessage;

interface KafkaConsumer
{
    /**
     * @return iterable<int, KafkaNotificationMessage>
     */
    public function consume(string $topic, int $limit): iterable;
}
