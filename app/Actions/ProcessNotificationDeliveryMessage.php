<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\NotificationGateway;
use App\DTO\GatewayResult;
use App\DTO\KafkaNotificationMessage;
use App\DTO\NotificationDeliveryProcessingResult;
use App\Enums\DeliveryAttemptStatus;
use App\Enums\GatewayResultStatus;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Services\Notifications\NotificationDeliveryMessageValidator;
use App\Services\Notifications\NotificationGatewayResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNotificationDeliveryMessage
{
    public function __construct(
        private readonly NotificationDeliveryMessageValidator $messageValidator,
        private readonly NotificationGatewayResolver $gatewayResolver,
    ) {}

    public function __invoke(KafkaNotificationMessage $message): NotificationDeliveryProcessingResult
    {
        $validationResult = $this->messageValidator->validate($message);

        if ($validationResult->isInvalid()) {
            Log::error('Notification delivery message payload is malformed.', [
                'topic' => $message->topic,
                'key' => $message->key,
                'metadata' => $message->metadata,
                'errors' => $validationResult->invalidFields,
            ]);

            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Invalid,
                reason: $validationResult->reason,
            );
        }

        $payload = $validationResult->payload();
        $notificationId = $payload['notification_id'];
        $attemptNumber = $payload['attempt'];

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

        $gateway = $this->gatewayResolver->forChannel($notification->channel);

        /** @var DeliveryAttempt|null $existingAttempt */
        $existingAttempt = DeliveryAttempt::query()
            ->where('notification_id', $notification->id)
            ->where('attempt_number', $attemptNumber)
            ->first();

        if ($existingAttempt !== null) {
            Log::info('Notification delivery message skipped because delivery attempt already exists.', [
                'topic' => $message->topic,
                'notification_id' => $notification->id,
                'attempt' => $attemptNumber,
                'gateway' => $existingAttempt->gateway,
                'existing_attempt_status' => $existingAttempt->status->value,
            ]);

            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Consumed,
                notificationId: $notification->id,
                reason: 'duplicate_attempt',
            );
        }

        $attempt = $this->createPendingAttempt($notification, $gateway, $attemptNumber);

        if ($attempt === null) {
            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Consumed,
                notificationId: $notification->id,
                reason: 'duplicate_attempt',
            );
        }

        try {
            $gatewayResult = $gateway->send($notification);
        } catch (Throwable $exception) {
            Log::error('Notification gateway send threw an unexpected exception.', [
                'notification_id' => $notification->id,
                'attempt' => $attemptNumber,
                'gateway_class' => $gateway::class,
                'exception_class' => $exception::class,
            ]);

            $gatewayResult = GatewayResult::temporaryFailure(
                gatewayName: class_basename($gateway),
                errorCode: 'gateway_exception',
                errorMessage: $exception->getMessage(),
            );
        }

        $this->persistGatewayResult($notification, $attempt, $gatewayResult);

        return new NotificationDeliveryProcessingResult(
            status: NotificationDeliveryProcessingStatus::Consumed,
            notificationId: $notification->id,
        );
    }

    private function createPendingAttempt(
        Notification $notification,
        NotificationGateway $gateway,
        int $attemptNumber,
    ): ?DeliveryAttempt {
        try {
            /** @var DeliveryAttempt $attempt */
            $attempt = DeliveryAttempt::query()->create([
                'notification_id' => $notification->id,
                'gateway' => class_basename($gateway),
                'status' => DeliveryAttemptStatus::Pending,
                'attempt_number' => $attemptNumber,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            Log::info('Notification delivery message skipped because delivery attempt already exists.', [
                'notification_id' => $notification->id,
                'attempt' => $attemptNumber,
                'gateway' => class_basename($gateway),
                'existing_attempt_status' => 'unknown',
            ]);

            return null;
        }

        return $attempt;
    }

    private function persistGatewayResult(
        Notification $notification,
        DeliveryAttempt $attempt,
        GatewayResult $gatewayResult,
    ): void {
        DB::transaction(function () use ($notification, $attempt, $gatewayResult): void {
            $attempt->update([
                'gateway' => $gatewayResult->gatewayName,
                'status' => $this->deliveryAttemptStatus($gatewayResult),
                'error_code' => $gatewayResult->errorCode,
                'error_message' => $gatewayResult->errorMessage,
            ]);

            if ($gatewayResult->targetNotificationStatus !== null) {
                $notification->update([
                    'status' => $gatewayResult->targetNotificationStatus,
                ]);
            }
        });

        $context = [
            'notification_id' => $notification->id,
            'attempt' => $attempt->attempt_number,
            'gateway' => $gatewayResult->gatewayName,
            'status' => $gatewayResult->status->value,
            'error_code' => $gatewayResult->errorCode,
            'target_notification_status' => $gatewayResult->targetNotificationStatus?->value,
        ];

        if ($gatewayResult->succeeded()) {
            Log::info('Notification gateway outcome persisted.', $context);
        } else {
            Log::warning('Notification gateway failure outcome persisted.', $context);
        }
    }

    private function deliveryAttemptStatus(GatewayResult $gatewayResult): DeliveryAttemptStatus
    {
        return match ($gatewayResult->status) {
            GatewayResultStatus::Succeeded => DeliveryAttemptStatus::Succeeded,
            GatewayResultStatus::TemporaryFailed => DeliveryAttemptStatus::TemporaryFailed,
            GatewayResultStatus::PermanentlyFailed => DeliveryAttemptStatus::PermanentlyFailed,
        };
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
