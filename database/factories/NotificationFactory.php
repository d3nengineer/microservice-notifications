<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'batch_id' => NotificationBatch::factory(),
            'recipient_id' => fake()->uuid(),
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'message' => fake()->paragraph(),
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Queued,
            'deduplication_key' => fake()->unique()->uuid(),
        ];
    }

    public function highPriority(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => NotificationPriority::High,
        ]);
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Queued,
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Delivered,
        ]);
    }
}
