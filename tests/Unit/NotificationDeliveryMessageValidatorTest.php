<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\KafkaNotificationMessage;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Services\Notifications\NotificationDeliveryMessageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationDeliveryMessageValidatorTest extends TestCase
{
    public function test_it_accepts_valid_notification_delivery_payloads(): void
    {
        $result = app(NotificationDeliveryMessageValidator::class)->validate(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: $this->validPayload(),
        ));

        $this->assertFalse($result->isInvalid());
        $this->assertSame([
            'notification_id' => 123,
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'priority' => NotificationPriority::High->value,
            'attempt' => 1,
        ], $result->payload);
        $this->assertSame([], $result->invalidFields);
        $this->assertNull($result->reason);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @param  array<int, string>  $expectedInvalidFields
     */
    #[DataProvider('malformedPayloadProvider')]
    public function test_it_rejects_malformed_notification_delivery_payloads(
        array $payloadOverrides,
        array $expectedInvalidFields,
    ): void {
        $result = app(NotificationDeliveryMessageValidator::class)->validate(new KafkaNotificationMessage(
            topic: 'notifications.high',
            payload: [
                ...$this->validPayload(),
                ...$payloadOverrides,
            ],
        ));

        $this->assertTrue($result->isInvalid());
        $this->assertNull($result->payload);
        $this->assertSame('malformed_payload', $result->reason);
        $this->assertSame($expectedInvalidFields, $result->invalidFields);
    }

    /**
     * @return array<string, array{payloadOverrides: array<string, mixed>, expectedInvalidFields: array<int, string>}>
     */
    public static function malformedPayloadProvider(): array
    {
        return [
            'invalid channel' => [
                'payloadOverrides' => ['channel' => 'push'],
                'expectedInvalidFields' => ['channel'],
            ],
            'missing message' => [
                'payloadOverrides' => ['message' => null],
                'expectedInvalidFields' => ['message'],
            ],
            'invalid priority' => [
                'payloadOverrides' => ['priority' => 'urgent'],
                'expectedInvalidFields' => ['priority'],
            ],
            'invalid attempt' => [
                'payloadOverrides' => ['attempt' => 0],
                'expectedInvalidFields' => ['attempt'],
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
    private function validPayload(): array
    {
        return [
            'notification_id' => 123,
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'priority' => NotificationPriority::High->value,
            'attempt' => 1,
        ];
    }
}
