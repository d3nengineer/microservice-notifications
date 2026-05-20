<?php

declare(strict_types=1);

namespace App\Services\Notifications\Gateways;

use App\Contracts\NotificationGateway;
use App\DTO\GatewayResult;
use App\Enums\GatewayResultStatus;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class SmsGateway implements NotificationGateway
{
    public function __construct(
        private readonly GatewayResultStatus $unavailableStatus = GatewayResultStatus::TemporaryFailed,
    ) {}

    public function send(Notification $notification): GatewayResult
    {
        $result = $this->unavailableResult();

        Log::warning('Notification gateway is unavailable.', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'gateway' => $this->name(),
            'status' => $result->status->value,
            'error_code' => $result->errorCode,
        ]);

        return $result;
    }

    private function unavailableResult(): GatewayResult
    {
        if ($this->unavailableStatus === GatewayResultStatus::PermanentlyFailed) {
            return GatewayResult::permanentFailure(
                gatewayName: $this->name(),
                errorCode: 'gateway_unavailable',
                errorMessage: 'SMS gateway is not configured.',
            );
        }

        return GatewayResult::temporaryFailure(
            gatewayName: $this->name(),
            errorCode: 'gateway_unavailable',
            errorMessage: 'SMS gateway is not configured.',
        );
    }

    private function name(): string
    {
        return 'sms';
    }
}
