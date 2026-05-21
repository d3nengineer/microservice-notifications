<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Actions\ProcessNotificationDeliveryMessage;
use App\DTO\KafkaNotificationMessage;
use App\Enums\DeliveryAttemptStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationDeliveryProcessingStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Enums\OutboxMessageStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Services\Notifications\Gateways\FakeGateway;
use App\Services\Notifications\NotificationGatewayRateLimiter;
use App\Services\Notifications\NotificationKafkaPayloadBuilder;
use App\Services\Notifications\NotificationKafkaTopicResolver;
use App\Services\Notifications\StageNotificationRetryOutboxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Mockery\VerificationDirector;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessNotificationDeliveryMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sends_queued_notifications_through_the_gateway(): void
    {
        $gateway = $this->useFakeGateway();

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification),
            key: 'notification:'.$notification->id,
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(NotificationDeliveryProcessingStatus::Consumed, $result->status);
        $this->assertSame($notification->id, $result->notificationId);
        $this->assertSame(1, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::Succeeded->value,
            'attempt_number' => 1,
            'error_code' => null,
            'error_message' => null,
        ]);
        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
    }

    public function test_it_records_temporary_gateway_failures_and_stages_retry_outbox_message(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        config()->set('notifications.delivery.max_attempts', 3);
        config()->set('notifications.delivery.backoff_seconds', 60);
        config()->set('notifications.delivery.max_backoff_seconds', 900);

        $gateway = $this->useFakeGateway();
        $gateway->temporarilyFail(errorCode: 'timeout', errorMessage: 'Gateway timed out.');

        /** @var Notification $notification */
        $notification = Notification::factory()->highPriority()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($notification, attempt: 1),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(1, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 1,
            'error_code' => 'timeout',
            'error_message' => 'Gateway timed out.',
        ]);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'topic' => 'notifications.high',
            'status' => OutboxMessageStatus::Pending->value,
            'attempts' => 0,
        ]);

        /** @var OutboxMessage $outboxMessage */
        $outboxMessage = OutboxMessage::query()->firstOrFail();

        $this->assertSame(2, $outboxMessage->payload['attempt']);
        $this->assertTrue($outboxMessage->available_at->equalTo(Carbon::parse('2026-05-20 10:01:00')));
    }

    public function test_it_drops_notification_after_temporary_gateway_failure_exhausts_attempts(): void
    {
        config()->set('notifications.delivery.max_attempts', 3);

        $gateway = $this->useFakeGateway();
        $gateway->temporarilyFail(errorCode: 'timeout', errorMessage: 'Gateway timed out.');

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification, attempt: 3),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(1, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 3,
            'error_code' => 'timeout',
            'error_message' => 'Gateway timed out.',
        ]);
        $this->assertSame(NotificationStatus::Dropped, $notification->refresh()->status);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
    }

    public function test_it_removes_pending_attempt_after_retry_staging_fails_so_redelivery_can_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        config()->set('notifications.delivery.max_attempts', 3);
        config()->set('notifications.delivery.backoff_seconds', 60);
        config()->set('notifications.delivery.max_backoff_seconds', 900);

        $gateway = $this->useFakeGateway();
        $gateway->temporarilyFail(errorCode: 'timeout', errorMessage: 'Gateway timed out.');

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $this->app->instance(
            StageNotificationRetryOutboxMessage::class,
            new class(app(NotificationKafkaTopicResolver::class), app(NotificationKafkaPayloadBuilder::class)) extends StageNotificationRetryOutboxMessage
            {
                private bool $shouldFail = true;

                public function __invoke(
                    Notification $notification,
                    int $currentAttempt,
                    int $delaySeconds,
                    ?Carbon $now = null,
                ): OutboxMessage {
                    if ($this->shouldFail) {
                        $this->shouldFail = false;

                        throw new RuntimeException('Unable to stage retry.');
                    }

                    return parent::__invoke($notification, $currentAttempt, $delaySeconds, $now);
                }
            },
        );

        try {
            app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
                topic: 'notifications.normal',
                payload: $this->validPayload($notification),
            ));
            $this->fail('Expected retry staging failure to be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to stage retry.', $exception->getMessage());
        }

        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(2, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 1,
            'error_code' => 'timeout',
            'error_message' => 'Gateway timed out.',
        ]);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'topic' => 'notifications.normal',
            'status' => OutboxMessageStatus::Pending->value,
            'attempts' => 0,
        ]);

        /** @var OutboxMessage $outboxMessage */
        $outboxMessage = OutboxMessage::query()->firstOrFail();

        $this->assertSame(2, $outboxMessage->payload['attempt']);
        $this->assertTrue($outboxMessage->available_at->equalTo(Carbon::parse('2026-05-20 10:01:00')));
    }

    public function test_it_records_permanent_gateway_failures_and_drops_notification(): void
    {
        $gateway = $this->useFakeGateway();
        $gateway->permanentlyFail(errorCode: 'invalid_recipient', errorMessage: 'Recipient is invalid.');

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(1, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'status' => DeliveryAttemptStatus::PermanentlyFailed->value,
            'attempt_number' => 1,
            'error_code' => 'invalid_recipient',
            'error_message' => 'Recipient is invalid.',
        ]);
        $this->assertSame(NotificationStatus::Dropped, $notification->refresh()->status);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
    }

    public function test_it_skips_duplicate_same_attempt_redelivery_without_calling_gateway_again(): void
    {
        $gateway = $this->useFakeGateway();

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();
        DeliveryAttempt::factory()->succeeded()->create([
            'notification_id' => $notification->id,
            'gateway' => 'fake',
            'attempt_number' => 4,
        ]);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification, attempt: 4),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame('duplicate_attempt', $result->reason);
        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 1);
        $this->assertDatabaseCount((new OutboxMessage)->getTable(), 0);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
    }

    public function test_it_treats_gateway_rate_limits_as_temporary_failures_without_calling_gateway(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        config()->set('notifications.cache.rate_limits.channels.email.max_attempts', 1);
        config()->set('notifications.cache.rate_limits.channels.email.decay_seconds', 60);
        config()->set('notifications.delivery.max_attempts', 3);
        config()->set('notifications.delivery.backoff_seconds', 60);

        $gateway = $this->useFakeGateway();

        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create([
            'channel' => NotificationChannel::Email,
        ]);

        app(NotificationGatewayRateLimiter::class)->attempt($notification, 'FakeGateway');
        /** @var MockInterface $logger */
        $logger = Mockery::spy();
        Log::swap($logger);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification),
        ));

        $this->assertTrue($result->isConsumed());
        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertDatabaseHas((new DeliveryAttempt)->getTable(), [
            'notification_id' => $notification->id,
            'gateway' => 'FakeGateway',
            'status' => DeliveryAttemptStatus::TemporaryFailed->value,
            'attempt_number' => 1,
            'error_code' => 'gateway_rate_limited',
        ]);
        $this->assertSame(NotificationStatus::Queued, $notification->refresh()->status);
        $this->assertDatabaseHas((new OutboxMessage)->getTable(), [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'status' => OutboxMessageStatus::Pending->value,
        ]);
        $this->assertSame(2, OutboxMessage::query()->sole()->payload['attempt']);
        /** @var VerificationDirector $rateLimitWarning */
        $rateLimitWarning = $logger->shouldHaveReceived('warning', [
            'Notification gateway rate limit reached.',
            Mockery::type('array'),
        ]);
        $rateLimitWarning->once();

        /** @var VerificationDirector $processorWarning */
        $processorWarning = $logger->shouldHaveReceived('warning', [
            'Notification gateway send skipped because rate limit was reached.',
            Mockery::type('array'),
        ]);
        $processorWarning->once();
    }

    public function test_it_skips_notifications_in_final_statuses(): void
    {
        $gateway = $this->useFakeGateway();

        /** @var Notification $notification */
        $notification = Notification::factory()->delivered()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload($notification, attempt: 2),
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertSame($notification->id, $result->notificationId);
        $this->assertSame('final_status', $result->reason);
        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }

    public function test_it_reports_missing_notifications(): void
    {
        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.low',
            payload: [
                'notification_id' => 999_999,
                'recipient_id' => 'subscriber-1',
                'channel' => NotificationChannel::Email->value,
                'message' => 'Your verification code is 1234',
                'priority' => NotificationPriority::Normal->value,
                'attempt' => 1,
            ],
        ));

        $this->assertTrue($result->isMissing());
        $this->assertSame(999_999, $result->notificationId);
        $this->assertSame('notification_missing', $result->reason);
    }

    public function test_it_reports_malformed_payloads(): void
    {
        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                'notification_id' => 'not-an-integer',
                'attempt' => 1,
            ],
            metadata: ['partition' => 1],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->notificationId);
        $this->assertSame('malformed_payload', $result->reason);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     */
    #[DataProvider('malformedPayloadProvider')]
    public function test_it_reports_malformed_payload_fields(array $payloadOverrides): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->queued()->create();

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: [
                ...$this->validPayload($notification),
                ...$payloadOverrides,
            ],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->notificationId);
        $this->assertSame('malformed_payload', $result->reason);
    }

    public function test_it_skips_dropped_notifications_without_delivery_attempts(): void
    {
        $gateway = $this->useFakeGateway();

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'status' => NotificationStatus::Dropped,
        ]);

        $result = app(ProcessNotificationDeliveryMessage::class)(new KafkaNotificationMessage(
            topic: 'notifications.normal',
            payload: $this->validPayload($notification, attempt: 3),
        ));

        $this->assertTrue($result->isSkipped());
        $this->assertSame(0, $gateway->sendCount($notification->id));
        $this->assertDatabaseCount((new DeliveryAttempt)->getTable(), 0);
    }

    /**
     * @return array<string, array{payloadOverrides: array<string, mixed>}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'invalid channel' => [
                'payloadOverrides' => ['channel' => 'push'],
            ],
            'missing message' => [
                'payloadOverrides' => ['message' => null],
            ],
            'invalid priority' => [
                'payloadOverrides' => ['priority' => 'urgent'],
            ],
            'invalid attempt' => [
                'payloadOverrides' => ['attempt' => 0],
            ],
        ];
    }

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
    private function validPayload(Notification $notification, int $attempt = 1): array
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

    private function useFakeGateway(): FakeGateway
    {
        config()->set('notifications.gateways.channels.email', 'fake');
        config()->set('notifications.gateways.channels.sms', 'fake');

        $gateway = new FakeGateway;
        $this->app->instance(FakeGateway::class, $gateway);

        return $gateway;
    }
}
