<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTO\GatewayResult;
use App\Enums\GatewayResultStatus;
use App\Enums\NotificationStatus;
use PHPUnit\Framework\TestCase;

class GatewayResultTest extends TestCase
{
    public function test_success_result_exposes_sent_target_status(): void
    {
        $result = GatewayResult::success('fake');

        $this->assertSame(GatewayResultStatus::Succeeded, $result->status);
        $this->assertSame('fake', $result->gatewayName);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->errorMessage);
        $this->assertSame(NotificationStatus::Sent, $result->targetNotificationStatus);
        $this->assertTrue($result->succeeded());
    }

    public function test_temporary_failure_result_keeps_notification_status_unchanged(): void
    {
        $result = GatewayResult::temporaryFailure('fake', 'timeout', 'Gateway timed out.');

        $this->assertSame(GatewayResultStatus::TemporaryFailed, $result->status);
        $this->assertSame('timeout', $result->errorCode);
        $this->assertSame('Gateway timed out.', $result->errorMessage);
        $this->assertNull($result->targetNotificationStatus);
        $this->assertTrue($result->temporarilyFailed());
    }

    public function test_permanent_failure_result_exposes_dropped_target_status(): void
    {
        $result = GatewayResult::permanentFailure('fake', 'invalid_recipient', 'Recipient is invalid.');

        $this->assertSame(GatewayResultStatus::PermanentlyFailed, $result->status);
        $this->assertSame('invalid_recipient', $result->errorCode);
        $this->assertSame('Recipient is invalid.', $result->errorMessage);
        $this->assertSame(NotificationStatus::Dropped, $result->targetNotificationStatus);
        $this->assertTrue($result->permanentlyFailed());
    }
}
