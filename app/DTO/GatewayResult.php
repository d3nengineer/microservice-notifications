<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enums\GatewayResultStatus;
use App\Enums\NotificationStatus;

class GatewayResult
{
    public function __construct(
        public readonly GatewayResultStatus $status,
        public readonly string $gatewayName,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?NotificationStatus $targetNotificationStatus = null,
    ) {}

    public static function success(string $gatewayName): self
    {
        return new self(
            status: GatewayResultStatus::Succeeded,
            gatewayName: $gatewayName,
            targetNotificationStatus: NotificationStatus::Sent,
        );
    }

    public static function temporaryFailure(
        string $gatewayName,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: GatewayResultStatus::TemporaryFailed,
            gatewayName: $gatewayName,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public static function permanentFailure(
        string $gatewayName,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): self {
        return new self(
            status: GatewayResultStatus::PermanentlyFailed,
            gatewayName: $gatewayName,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            targetNotificationStatus: NotificationStatus::Dropped,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === GatewayResultStatus::Succeeded;
    }

    public function temporarilyFailed(): bool
    {
        return $this->status === GatewayResultStatus::TemporaryFailed;
    }

    public function permanentlyFailed(): bool
    {
        return $this->status === GatewayResultStatus::PermanentlyFailed;
    }
}
