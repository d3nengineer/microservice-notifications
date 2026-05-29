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
use App\Enums\NotificationStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Services\Notifications\NotificationDeliveryMessageValidator;
use App\Services\Notifications\NotificationDeliveryRetryPolicy;
use App\Services\Notifications\NotificationGatewayRateLimiter;
use App\Services\Notifications\NotificationGatewayResolver;
use App\Services\Notifications\StageNotificationRetryOutboxMessage;
use App\Services\Notifications\SubscriberNotificationHistoryCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessNotificationDeliveryMessage
{
    public function __construct(
        private readonly NotificationDeliveryMessageValidator $messageValidator,
        private readonly NotificationGatewayResolver $gatewayResolver,
        private readonly NotificationDeliveryRetryPolicy $retryPolicy,
        private readonly StageNotificationRetryOutboxMessage $stageRetryOutboxMessage,
        private readonly NotificationGatewayRateLimiter $gatewayRateLimiter,
        private readonly SubscriberNotificationHistoryCache $historyCache,
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
        $gatewayName = $this->gatewayName($gateway);

        /** @var DeliveryAttempt|null $existingAttempt */
        $existingAttempt = $this->existingAttempt($notification, $attemptNumber);

        if ($existingAttempt !== null) {
            return $this->existingAttemptResult($message, $notification, $existingAttempt, $attemptNumber);
        }

        $attempt = $this->createPendingAttempt($notification, $gateway, $attemptNumber);

        if ($attempt === null) {
            return new NotificationDeliveryProcessingResult(
                status: NotificationDeliveryProcessingStatus::Consumed,
                notificationId: $notification->id,
                reason: 'duplicate_attempt',
            );
        }

        $rateLimit = $this->gatewayRateLimiter->attempt($notification, $gatewayName);

        if (! $rateLimit->allowed) {
            Log::warning('Notification gateway send skipped because rate limit was reached.', [
                'notification_id' => $notification->id,
                'attempt' => $attemptNumber,
                'gateway' => $gatewayName,
                'channel' => $notification->channel->value,
                'max_attempts' => $rateLimit->maxAttempts,
                'decay_seconds' => $rateLimit->decaySeconds,
                'retry_after_seconds' => $rateLimit->retryAfterSeconds,
            ]);

            $gatewayResult = GatewayResult::temporaryFailure(
                gatewayName: $gatewayName,
                errorCode: 'gateway_rate_limited',
                errorMessage: 'Gateway rate limit reached.',
            );
        } else {
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
                    gatewayName: $gatewayName,
                    errorCode: 'gateway_exception',
                    errorMessage: $exception->getMessage(),
                );
            }
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
                'gateway' => $this->gatewayName($gateway),
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
                'gateway' => $this->gatewayName($gateway),
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
        try {
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
                    $this->historyCache->invalidate($notification->recipient_id, 'notification_status_changed');

                    Log::info('Notification status transitioned after gateway outcome.', [
                        'notification_id' => $notification->id,
                        'attempt' => $attempt->attempt_number,
                        'status' => $gatewayResult->targetNotificationStatus->value,
                        'gateway' => $gatewayResult->gatewayName,
                    ]);
                }

                if ($gatewayResult->temporarilyFailed()) {
                    $this->handleTemporaryFailure($notification, $attempt, $gatewayResult);
                }
            });
        } catch (Throwable $exception) {
            if ($gatewayResult->temporarilyFailed()) {
                $this->removePendingAttemptAfterRetryPersistenceFailure($attempt, $gatewayResult, $exception);
            }

            throw $exception;
        }

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

    private function handleTemporaryFailure(
        Notification $notification,
        DeliveryAttempt $attempt,
        GatewayResult $gatewayResult,
    ): void {
        Log::info('Notification temporary gateway failure is being handled.', [
            'notification_id' => $notification->id,
            'attempt' => $attempt->attempt_number,
            'gateway' => $gatewayResult->gatewayName,
            'error_code' => $gatewayResult->errorCode,
        ]);

        if ($this->retryPolicy->isExhausted($attempt->attempt_number)) {
            $notification->update([
                'status' => NotificationStatus::Dropped,
            ]);
            $this->historyCache->invalidate($notification->recipient_id, 'notification_status_changed');

            Log::warning('Notification temporary gateway failure exhausted retry attempts.', [
                'notification_id' => $notification->id,
                'attempt' => $attempt->attempt_number,
                'gateway' => $gatewayResult->gatewayName,
                'max_attempts' => $this->retryPolicy->maxAttempts(),
                'status' => NotificationStatus::Dropped->value,
            ]);

            return;
        }

        $delaySeconds = $this->retryPolicy->delaySecondsForAttempt($attempt->attempt_number);

        try {
            ($this->stageRetryOutboxMessage)(
                notification: $notification,
                currentAttempt: $attempt->attempt_number,
                delaySeconds: $delaySeconds,
            );
        } catch (Throwable $exception) {
            Log::error('Notification retry scheduling failed unexpectedly.', [
                'notification_id' => $notification->id,
                'attempt' => $attempt->attempt_number,
                'gateway' => $gatewayResult->gatewayName,
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        Log::info('Notification retry scheduling completed for temporary gateway failure.', [
            'notification_id' => $notification->id,
            'current_attempt' => $attempt->attempt_number,
            'next_attempt' => $attempt->attempt_number + 1,
            'gateway' => $gatewayResult->gatewayName,
            'delay_seconds' => $delaySeconds,
            'status' => NotificationStatus::Queued->value,
        ]);
    }

    private function removePendingAttemptAfterRetryPersistenceFailure(
        DeliveryAttempt $attempt,
        GatewayResult $gatewayResult,
        Throwable $exception,
    ): void {
        try {
            $deletedAttempts = DeliveryAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', DeliveryAttemptStatus::Pending)
                ->delete();
        } catch (Throwable $cleanupException) {
            Log::error('Notification pending delivery attempt cleanup failed after retry persistence failure.', [
                'notification_id' => $attempt->notification_id,
                'attempt' => $attempt->attempt_number,
                'gateway' => $gatewayResult->gatewayName,
                'exception_class' => $exception::class,
                'cleanup_exception_class' => $cleanupException::class,
            ]);

            return;
        }

        Log::warning('Notification pending delivery attempt removed after retry persistence failure.', [
            'notification_id' => $attempt->notification_id,
            'attempt' => $attempt->attempt_number,
            'gateway' => $gatewayResult->gatewayName,
            'exception_class' => $exception::class,
            'deleted_attempts' => $deletedAttempts,
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }

    private function existingAttempt(Notification $notification, int $attemptNumber): ?DeliveryAttempt
    {
        /** @var DeliveryAttempt|null $attempt */
        $attempt = DeliveryAttempt::query()
            ->where('notification_id', $notification->id)
            ->where('attempt_number', $attemptNumber)
            ->first();

        return $attempt;
    }

    private function existingAttemptResult(
        KafkaNotificationMessage $message,
        Notification $notification,
        DeliveryAttempt $existingAttempt,
        int $attemptNumber,
    ): NotificationDeliveryProcessingResult {
        if (
            $existingAttempt->status === DeliveryAttemptStatus::Pending
            && $this->pendingAttemptIsStale($existingAttempt)
        ) {
            return $this->recoverStalePendingAttempt($message, $notification, $existingAttempt, $attemptNumber);
        }

        return $this->duplicateAttemptResult($message, $notification, $existingAttempt, $attemptNumber);
    }

    private function pendingAttemptIsStale(DeliveryAttempt $attempt): bool
    {
        if ($attempt->updated_at === null) {
            return false;
        }

        return $attempt->updated_at->lte(now()->subSeconds($this->pendingAttemptTimeoutSeconds()));
    }

    private function recoverStalePendingAttempt(
        KafkaNotificationMessage $message,
        Notification $notification,
        DeliveryAttempt $attempt,
        int $attemptNumber,
    ): NotificationDeliveryProcessingResult {
        $gatewayName = $attempt->gateway;
        $gatewayResult = GatewayResult::temporaryFailure(
            gatewayName: $gatewayName,
            errorCode: 'stale_pending_attempt',
            errorMessage: 'Delivery attempt stayed pending past the configured timeout.',
        );

        Log::warning('Notification stale pending delivery attempt is being recovered.', [
            'topic' => $message->topic,
            'notification_id' => $notification->id,
            'attempt' => $attemptNumber,
            'gateway' => $gatewayName,
            'pending_attempt_timeout_seconds' => $this->pendingAttemptTimeoutSeconds(),
        ]);

        $this->persistGatewayResult($notification, $attempt, $gatewayResult);

        return new NotificationDeliveryProcessingResult(
            status: NotificationDeliveryProcessingStatus::Consumed,
            notificationId: $notification->id,
            reason: 'stale_pending_attempt_recovered',
        );
    }

    private function duplicateAttemptResult(
        KafkaNotificationMessage $message,
        Notification $notification,
        DeliveryAttempt $existingAttempt,
        int $attemptNumber,
    ): NotificationDeliveryProcessingResult {
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

    private function pendingAttemptTimeoutSeconds(): int
    {
        $value = config('notifications.delivery.pending_attempt_timeout_seconds', 300);

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        return 300;
    }

    private function gatewayName(NotificationGateway $gateway): string
    {
        return class_basename($gateway);
    }
}
