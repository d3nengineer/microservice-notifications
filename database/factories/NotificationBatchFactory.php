<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationBatch>
 */
class NotificationBatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => fake()->randomElement(NotificationChannel::cases()),
            'message' => fake()->paragraph(),
            'priority' => NotificationPriority::Normal,
            'idempotency_key' => fake()->unique()->uuid(),
            'payload_hash' => fake()->sha256(),
            'status' => NotificationStatus::Queued,
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
