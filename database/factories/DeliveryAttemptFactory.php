<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeliveryAttemptStatus;
use App\Models\DeliveryAttempt;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryAttempt>
 */
class DeliveryAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'gateway' => fake()->randomElement(['smtp', 'sms-gateway']),
            'status' => DeliveryAttemptStatus::Pending,
            'attempt_number' => fake()->numberBetween(1, 3),
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function temporaryFailed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryAttemptStatus::TemporaryFailed,
            'error_code' => 'gateway_timeout',
            'error_message' => 'Gateway request timed out.',
        ]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DeliveryAttemptStatus::Succeeded,
            'error_code' => null,
            'error_message' => null,
        ]);
    }
}
