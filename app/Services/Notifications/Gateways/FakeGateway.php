<?php

declare(strict_types=1);

namespace App\Services\Notifications\Gateways;

use App\Contracts\NotificationGateway;
use App\DTO\GatewayResult;
use App\Enums\NotificationChannel;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

class FakeGateway implements NotificationGateway
{
    /**
     * @var array<int, GatewayResult>
     */
    private array $notificationResults = [];

    /**
     * @var array<string, GatewayResult>
     */
    private array $channelResults = [];

    /**
     * @var array<int, int>
     */
    private array $sendCountsByNotification = [];

    /**
     * @var array<int, int>
     */
    private array $sentNotificationIds = [];

    private GatewayResult $defaultResult;

    public function __construct(?GatewayResult $defaultResult = null)
    {
        $this->defaultResult = $defaultResult ?? GatewayResult::success($this->name());
    }

    public function send(Notification $notification): GatewayResult
    {
        $this->sendCountsByNotification[$notification->id] = $this->sendCount($notification->id) + 1;
        $this->sentNotificationIds[] = $notification->id;

        $result = $this->notificationResults[$notification->id]
            ?? $this->channelResults[$notification->channel->value]
            ?? $this->defaultResult;

        $context = [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'gateway' => $result->gatewayName,
            'status' => $result->status->value,
            'error_code' => $result->errorCode,
        ];

        if ($result->succeeded()) {
            Log::info('Notification gateway send succeeded.', $context);
        } else {
            Log::warning('Notification gateway send failed.', $context);
        }

        return $result;
    }

    public function succeed(?NotificationChannel $channel = null): self
    {
        return $this->setChannelOrDefault(GatewayResult::success($this->name()), $channel);
    }

    public function temporarilyFail(
        ?NotificationChannel $channel = null,
        ?string $errorCode = 'temporary_failure',
        ?string $errorMessage = 'Temporary gateway failure.',
    ): self {
        return $this->setChannelOrDefault(
            GatewayResult::temporaryFailure($this->name(), $errorCode, $errorMessage),
            $channel,
        );
    }

    public function permanentlyFail(
        ?NotificationChannel $channel = null,
        ?string $errorCode = 'permanent_failure',
        ?string $errorMessage = 'Permanent gateway failure.',
    ): self {
        return $this->setChannelOrDefault(
            GatewayResult::permanentFailure($this->name(), $errorCode, $errorMessage),
            $channel,
        );
    }

    public function forNotification(int $notificationId, GatewayResult $result): self
    {
        $this->notificationResults[$notificationId] = $result;

        return $this;
    }

    public function sendCount(int $notificationId): int
    {
        return $this->sendCountsByNotification[$notificationId] ?? 0;
    }

    /**
     * @return array<int, int>
     */
    public function sentNotificationIds(): array
    {
        return $this->sentNotificationIds;
    }

    private function setChannelOrDefault(GatewayResult $result, ?NotificationChannel $channel): self
    {
        if ($channel === null) {
            $this->defaultResult = $result;

            return $this;
        }

        $this->channelResults[$channel->value] = $result;

        return $this;
    }

    private function name(): string
    {
        return 'fake';
    }
}
