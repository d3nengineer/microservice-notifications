<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IdempotencyKey;
use App\Models\NotificationBatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->uuid(),
            'payload_hash' => fake()->sha256(),
            'notification_batch_id' => NotificationBatch::factory(),
            'response_body' => [
                'status' => 'queued',
            ],
            'response_status' => 202,
        ];
    }
}
