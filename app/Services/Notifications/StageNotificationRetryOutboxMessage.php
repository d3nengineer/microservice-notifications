<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\OutboxMessageStatus;
use App\Models\Notification;
use App\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class StageNotificationRetryOutboxMessage
{
    public function __construct(
        private readonly NotificationKafkaTopicResolver $topicResolver,
        private readonly NotificationKafkaPayloadBuilder $payloadBuilder,
    ) {}

    public function __invoke(
        Notification $notification,
        int $currentAttempt,
        int $delaySeconds,
        ?Carbon $now = null,
    ): OutboxMessage {
        $nextAttempt = $currentAttempt + 1;
        $availableAt = ($now ?? now())->copy()->addSeconds($delaySeconds);

        try {
            $topic = $this->topicResolver->topicFor($notification->priority);

            /** @var OutboxMessage $outboxMessage */
            $outboxMessage = OutboxMessage::query()->create([
                'aggregate_type' => Notification::class,
                'aggregate_id' => $notification->id,
                'topic' => $topic,
                'payload' => $this->payloadBuilder->forNotification($notification, $nextAttempt),
                'status' => OutboxMessageStatus::Pending,
                'attempts' => 0,
                'available_at' => $availableAt,
                'published_at' => null,
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Notification retry outbox staging failed.', [
                'notification_id' => $notification->id,
                'current_attempt' => $currentAttempt,
                'next_attempt' => $nextAttempt,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        Log::info('Notification retry outbox message staged.', [
            'notification_id' => $notification->id,
            'current_attempt' => $currentAttempt,
            'next_attempt' => $nextAttempt,
            'topic' => $topic,
            'delay_seconds' => $delaySeconds,
            'available_at' => $availableAt->toISOString(),
        ]);

        return $outboxMessage;
    }
}
