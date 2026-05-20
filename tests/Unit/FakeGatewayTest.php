<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\GatewayResult;
use App\Enums\GatewayResultStatus;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use App\Services\Notifications\Gateways\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FakeGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_success_by_default(): void
    {
        $gateway = new FakeGateway;

        /** @var Notification $notification */
        $notification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        $result = $gateway->send($notification);

        $this->assertSame(GatewayResultStatus::Succeeded, $result->status);
        $this->assertSame(1, $gateway->sendCount($notification->id));
    }

    public function test_it_returns_channel_specific_temporary_failures(): void
    {
        $gateway = (new FakeGateway)->temporarilyFail(
            channel: NotificationChannel::Sms,
            errorCode: 'sms_timeout',
            errorMessage: 'SMS timed out.',
        );

        /** @var Notification $smsNotification */
        $smsNotification = Notification::factory()->create([
            'channel' => NotificationChannel::Sms,
        ]);
        /** @var Notification $emailNotification */
        $emailNotification = Notification::factory()->create([
            'channel' => NotificationChannel::Email,
        ]);

        $this->assertSame(GatewayResultStatus::TemporaryFailed, $gateway->send($smsNotification)->status);
        $this->assertSame(GatewayResultStatus::Succeeded, $gateway->send($emailNotification)->status);
    }

    public function test_it_returns_notification_specific_permanent_failures(): void
    {
        /** @var Notification $notification */
        $notification = Notification::factory()->create();

        $gateway = (new FakeGateway)->forNotification(
            $notification->id,
            GatewayResult::permanentFailure('fake', 'invalid_recipient', 'Recipient is invalid.'),
        );

        $result = $gateway->send($notification);

        $this->assertSame(GatewayResultStatus::PermanentlyFailed, $result->status);
        $this->assertSame('invalid_recipient', $result->errorCode);
    }
}
