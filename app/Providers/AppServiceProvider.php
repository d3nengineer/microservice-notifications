<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\KafkaConsumer;
use App\Contracts\KafkaProducer;
use App\Services\Kafka\FakeKafkaConsumer;
use App\Services\Kafka\FakeKafkaProducer;
use App\Services\Kafka\UnavailableKafkaConsumer;
use App\Services\Kafka\UnavailableKafkaProducer;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
