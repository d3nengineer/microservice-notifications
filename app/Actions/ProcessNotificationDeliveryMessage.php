<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTO\KafkaNotificationMessage;
use App\DTO\NotificationDeliveryProcessingResult;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class ProcessNotificationDeliveryMessage
{
    public function __invoke(KafkaNotificationMessage $message): NotificationDeliveryProcessingResult
    {
        $notificationId = $message->payload['notification_id'] ?? null;

        if (! is_int($notificationId)) {
            Log::error('Notification delivery message payload is malformed.', [
                'topic' => $message->topic,
                'key' => $message->key,
                'metadata' => $message->metadata,
            ]);

            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Invalid,
                reason: 'missing_notification_id',
            );
        }

        Log::info('Notification delivery message processing started.', [
            'topic' => $message->topic,
            'notification_id' => $notificationId,
            'attempt' => $message->payload['attempt'] ?? null,
        ]);

        /** @var Notification|null $notification */
        $notification = Notification::query()->find($notificationId);

        if ($notification === null) {
            Log::warning('Notification delivery message skipped because notification is missing.', [
                'topic' => $message->topic,
                'notification_id' => $notificationId,
            ]);

            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Missing,
                notificationId: $notificationId,
                reason: 'notification_missing',
            );
        }

        if (! $notification->canBeSent()) {
            Log::info('Notification delivery message skipped because notification cannot be sent.', [
                'topic' => $message->topic,
                'notification_id' => $notification->id,
                'status' => $notification->status->value,
            ]);

            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Skipped,
                notificationId: $notification->id,
                reason: 'final_status',
            );
        }

        Log::info('Notification delivery message accepted for gateway handoff.', [
            'topic' => $message->topic,
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
        ]);

        return new NotificationDeliveryProcessingResult(
            status: NotificationDeliveryProcessingStatus::Consumed,
            notificationId: $notification->id,
        );
    }
}
