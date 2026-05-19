<?php

declare(strict_types=1);

return [
    'kafka' => [
        'producer' => env('NOTIFICATION_KAFKA_PRODUCER', 'unavailable'),
        'consumer' => env('NOTIFICATION_KAFKA_CONSUMER', 'unavailable'),

        'topics' => [
            'high' => env('NOTIFICATION_KAFKA_TOPIC_HIGH', 'notifications.high'),
            'normal' => env('NOTIFICATION_KAFKA_TOPIC_NORMAL', 'notifications.normal'),
            'low' => env('NOTIFICATION_KAFKA_TOPIC_LOW', 'notifications.low'),
        ],

        'publisher' => [
            'limit' => (int) env('NOTIFICATION_OUTBOX_PUBLISH_LIMIT', 100),
            'max_attempts' => (int) env('NOTIFICATION_OUTBOX_MAX_ATTEMPTS', 3),
            'backoff_seconds' => (int) env('NOTIFICATION_OUTBOX_BACKOFF_SECONDS', 60),
        ],
    ],
];
