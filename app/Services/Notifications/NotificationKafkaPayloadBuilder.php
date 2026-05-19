<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;

class NotificationKafkaPayloadBuilder
{
    /**
     * @return array{
     *     notification_id: int,
     *     recipient_id: string,
     *     channel: string,
     *     message: string,
     *     priority: string,
     *     attempt: int
     * }
     */
    public function forNotification(Notification $notification, int $attempt = 1): array
    {
        return [
            'notification_id' => $notification->id,
            'recipient_id' => $notification->recipient_id,
            'channel' => $notification->channel->value,
            'message' => $notification->message,
            'priority' => $notification->priority->value,
            'attempt' => $attempt,
        ];
    }
}
