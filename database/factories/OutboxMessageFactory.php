<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\OutboxMessageStatus;
use App\Models\Notification;
use App\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboxMessage>
 */
class OutboxMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $notificationId = fake()->randomNumber();

        return [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notificationId,
            'topic' => 'notifications.normal',
            'payload' => [
                'notification_id' => $notificationId,
                'recipient_id' => fake()->uuid(),
                'channel' => NotificationChannel::Email->value,
                'message' => fake()->paragraph(),
                'priority' => NotificationPriority::Normal->value,
                'attempt' => 1,
            ],
            'status' => OutboxMessageStatus::Pending,
            'attempts' => 0,
            'available_at' => now(),
            'published_at' => null,
            'last_error' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OutboxMessageStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => OutboxMessageStatus::Failed,
            'last_error' => 'Kafka publish failed.',
        ]);
    }

    public function forNotification(Notification $notification): static
    {
        return $this->state(fn (array $attributes) => [
            'aggregate_type' => Notification::class,
            'aggregate_id' => $notification->id,
            'topic' => "notifications.{$notification->priority->value}",
            'payload' => [
                'notification_id' => $notification->id,
                'recipient_id' => $notification->recipient_id,
                'channel' => $notification->channel->value,
                'message' => $notification->message,
                'priority' => $notification->priority->value,
                'attempt' => 1,
            ],
        ]);
    }
}
