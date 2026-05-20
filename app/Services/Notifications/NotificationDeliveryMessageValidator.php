<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\DTO\KafkaNotificationMessage;
use App\DTO\NotificationDeliveryPayloadValidationResult;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class NotificationDeliveryMessageValidator
{
    public function validate(KafkaNotificationMessage $message): NotificationDeliveryPayloadValidationResult
    {
        $validator = Validator::make($message->payload, [
            'notification_id' => ['required', 'integer'],
            'recipient_id' => ['required', 'string'],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'message' => ['required', 'string'],
            'priority' => ['required', Rule::enum(NotificationPriority::class)],
            'attempt' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return new NotificationDeliveryPayloadValidationResult(
                valid: false,
                invalidFields: array_values($validator->errors()->keys()),
                reason: 'malformed_payload',
            );
        }

        /** @var array{notification_id: int|string, recipient_id: string, channel: string, message: string, priority: string, attempt: int|string} $validated */
        $validated = $validator->validated();

        return new NotificationDeliveryPayloadValidationResult(
            valid: true,
            payload: [
                'notification_id' => (int) $validated['notification_id'],
                'recipient_id' => $validated['recipient_id'],
                'channel' => $validated['channel'],
                'message' => $validated['message'],
                'priority' => $validated['priority'],
                'attempt' => (int) $validated['attempt'],
            ],
        );
    }
}
