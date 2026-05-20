<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\NotificationGateway;
use App\Enums\NotificationChannel;
use App\Services\Notifications\Gateways\EmailGateway;
use App\Services\Notifications\Gateways\FakeGateway;
use App\Services\Notifications\Gateways\SmsGateway;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class NotificationGatewayResolver
{
    /**
     * @param  array<string, string>  $channelModes
     */
    public function __construct(
        private readonly EmailGateway $emailGateway,
        private readonly SmsGateway $smsGateway,
        private readonly FakeGateway $fakeGateway,
        private readonly array $channelModes,
    ) {}

    public function forChannel(NotificationChannel $channel): NotificationGateway
    {
        $mode = $this->channelModes[$channel->value] ?? null;

        $gateway = match ($channel) {
            NotificationChannel::Email => $this->gatewayForMode($mode, $this->emailGateway, $channel),
            NotificationChannel::Sms => $this->gatewayForMode($mode, $this->smsGateway, $channel),
        };

        return $gateway;
    }

    private function gatewayForMode(
        ?string $mode,
        NotificationGateway $unavailableGateway,
        NotificationChannel $channel,
    ): NotificationGateway {
        return match ($mode) {
            'fake' => $this->fakeGateway,
            'unavailable' => $unavailableGateway,
            default => $this->unsupportedGatewayMode($channel, $mode),
        };
    }

    private function unsupportedGatewayMode(NotificationChannel $channel, ?string $mode): never
    {
        Log::error('Notification gateway configuration is unsupported.', [
            'channel' => $channel->value,
            'mode' => $mode,
        ]);

        throw new InvalidArgumentException(sprintf(
            'Unsupported notification gateway mode [%s] for channel [%s].',
            $mode ?? 'null',
            $channel->value,
        ));
    }
}
