<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\KafkaConsumer;
use App\Contracts\KafkaProducer;
use App\Enums\GatewayResultStatus;
use App\Services\Kafka\FakeKafkaConsumer;
use App\Services\Kafka\FakeKafkaProducer;
use App\Services\Kafka\UnavailableKafkaConsumer;
use App\Services\Kafka\UnavailableKafkaProducer;
use App\Services\Notifications\Gateways\EmailGateway;
use App\Services\Notifications\Gateways\FakeGateway;
use App\Services\Notifications\Gateways\SmsGateway;
use App\Services\Notifications\NotificationGatewayResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(KafkaProducer::class, function (): KafkaProducer {
            return match (config('notifications.kafka.producer')) {
                'fake' => new FakeKafkaProducer,
                default => new UnavailableKafkaProducer,
            };
        });

        $this->app->bind(KafkaConsumer::class, function (): KafkaConsumer {
            return match (config('notifications.kafka.consumer')) {
                'fake' => new FakeKafkaConsumer,
                default => new UnavailableKafkaConsumer,
            };
        });

        $this->app->bind(EmailGateway::class, function (): EmailGateway {
            return new EmailGateway($this->gatewayUnavailableStatus());
        });

        $this->app->bind(SmsGateway::class, function (): SmsGateway {
            return new SmsGateway($this->gatewayUnavailableStatus());
        });

        $this->app->bind(NotificationGatewayResolver::class, function (Application $app): NotificationGatewayResolver {
            /** @var array<string, string> $channelModes */
            $channelModes = config('notifications.gateways.channels', []);

            return new NotificationGatewayResolver(
                emailGateway: $app->make(EmailGateway::class),
                smsGateway: $app->make(SmsGateway::class),
                fakeGateway: $app->make(FakeGateway::class),
                channelModes: $channelModes,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function gatewayUnavailableStatus(): GatewayResultStatus
    {
        $configuredStatus = config('notifications.gateways.unavailable_result');

        if (! is_string($configuredStatus)) {
            return GatewayResultStatus::TemporaryFailed;
        }

        return GatewayResultStatus::tryFrom($configuredStatus)
            ?? GatewayResultStatus::TemporaryFailed;
    }
}
