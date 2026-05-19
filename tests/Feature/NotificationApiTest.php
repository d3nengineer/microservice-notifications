<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\IdempotencyKey;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_creation_succeeds_and_persists_notifications(): void
    {
        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1', 'subscriber-2'],
            'priority' => NotificationPriority::High->value,
        ], [
            'Idempotency-Key' => 'request-2026-05-18-001',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.notifications_count', 2)
            ->assertJsonPath('data.status', NotificationStatus::Queued->value);

        $this->assertDatabaseHas('notification_batches', [
            'id' => $response->json('data.batch_id'),
            'channel' => NotificationChannel::Email->value,
            'priority' => NotificationPriority::High->value,
            'idempotency_key' => 'request-2026-05-18-001',
            'status' => NotificationStatus::Queued->value,
        ]);

        $this->assertDatabaseHas('notifications', [
            'batch_id' => $response->json('data.batch_id'),
            'recipient_id' => 'subscriber-1',
            'status' => NotificationStatus::Queued->value,
        ]);

        $this->assertDatabaseCount('notifications', 2);

        /** @var IdempotencyKey $idempotencyKey */
        $idempotencyKey = IdempotencyKey::query()
            ->where('key', 'request-2026-05-18-001')
            ->firstOrFail();

        $this->assertSame($response->json(), $idempotencyKey->response_body);
        $this->assertSame(201, $idempotencyKey->response_status);
        $this->assertSame($response->json('data.batch_id'), $idempotencyKey->notification_batch_id);
    }

    public function test_batch_creation_replays_identical_idempotent_request(): void
    {
        $payload = [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1', 'subscriber-2'],
            'priority' => NotificationPriority::High->value,
        ];
        $headers = ['Idempotency-Key' => 'request-replay-001'];

        $firstResponse = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);
        $secondResponse = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);

        $firstResponse->assertCreated();
        $secondResponse->assertCreated();

        $this->assertSame($firstResponse->json(), $secondResponse->json());
        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_batch_creation_rejects_reused_idempotency_key_with_different_payload(): void
    {
        $payload = [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1', 'subscriber-2'],
            'priority' => NotificationPriority::High->value,
        ];
        $headers = ['Idempotency-Key' => 'request-conflict-001'];

        $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers)
            ->assertCreated();

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            ...$payload,
            'message' => 'Your verification code is 5678',
        ], $headers);

        $response
            ->assertConflict()
            ->assertJsonPath('error', 'idempotency_key_conflict');

        $this->assertDatabaseCount('notification_batches', 1);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_batch_creation_returns_locked_response_when_idempotency_lock_is_busy(): void
    {
        $idempotencyKey = 'request-locked-001';
        $lock = Cache::lock('notification-batches:idempotency:'.hash('sha256', $idempotencyKey), 10);

        $this->assertTrue($lock->get());

        try {
            $response = $this->postJson(route('api.v1.notification-batches.store'), [
                'channel' => NotificationChannel::Email->value,
                'message' => 'Your verification code is 1234',
                'recipient_ids' => ['subscriber-1'],
                'priority' => NotificationPriority::Normal->value,
            ], [
                'Idempotency-Key' => $idempotencyKey,
            ]);
        } finally {
            $lock->release();
        }

        $response
            ->assertStatus(423)
            ->assertJsonPath('error', 'idempotency_key_locked');

        $this->assertDatabaseCount('notification_batches', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('idempotency_keys', 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    #[DataProvider('invalidBatchPayloadProvider')]
    public function test_batch_creation_rejects_invalid_payloads(array $payload, array $headers, string $errorKey): void
    {
        $response = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);
    }

    /**
     * @return array<string, array{payload: array<string, mixed>, headers: array<string, string>, errorKey: string}>
     */
    public static function invalidBatchPayloadProvider(): array
    {
        $validPayload = [
            'channel' => NotificationChannel::Email->value,
            'message' => 'Your verification code is 1234',
            'recipient_ids' => ['subscriber-1'],
            'priority' => NotificationPriority::Normal->value,
        ];

        return [
            'missing idempotency header' => [
                'payload' => $validPayload,
                'headers' => [],
                'errorKey' => 'idempotency_key',
            ],
            'invalid channel' => [
                'payload' => [...$validPayload, 'channel' => 'push'],
                'headers' => ['Idempotency-Key' => 'request-invalid-channel'],
                'errorKey' => 'channel',
            ],
            'invalid priority' => [
                'payload' => [...$validPayload, 'priority' => 'urgent'],
                'headers' => ['Idempotency-Key' => 'request-invalid-priority'],
                'errorKey' => 'priority',
            ],
            'empty recipients' => [
                'payload' => [...$validPayload, 'recipient_ids' => []],
                'headers' => ['Idempotency-Key' => 'request-empty-recipients'],
                'errorKey' => 'recipient_ids',
            ],
            'duplicate recipients' => [
                'payload' => [...$validPayload, 'recipient_ids' => ['subscriber-1', 'subscriber-1']],
                'headers' => ['Idempotency-Key' => 'request-duplicate-recipients'],
                'errorKey' => 'recipient_ids.0',
            ],
        ];
    }

    public function test_subscriber_history_returns_only_requested_recipient_notifications(): void
    {
        /** @var Notification $requested */
        $requested = Notification::factory()->create([
            'recipient_id' => 'subscriber-1',
            'message' => 'Requested notification',
        ]);
        Notification::factory()->create([
            'recipient_id' => 'subscriber-2',
            'message' => 'Other subscriber notification',
        ]);

        $response = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-1',
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requested->id)
            ->assertJsonPath('data.0.recipient_id', 'subscriber-1');
    }

    public function test_subscriber_history_filters_by_status_and_channel(): void
    {
        /** @var Notification $matching */
        $matching = Notification::factory()->create([
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Email,
            'status' => NotificationStatus::Queued,
        ]);
        Notification::factory()->create([
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Sms,
            'status' => NotificationStatus::Queued,
        ]);
        Notification::factory()->create([
            'recipient_id' => 'subscriber-1',
            'channel' => NotificationChannel::Email,
            'status' => NotificationStatus::Delivered,
        ]);

        $response = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-1',
            'status' => NotificationStatus::Queued->value,
            'channel' => NotificationChannel::Email->value,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);
    }

    public function test_subscriber_history_rejects_invalid_filters(): void
    {
        $response = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-1',
            'status' => 'failed',
            'channel' => 'push',
        ]));

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'channel']);
    }

    public function test_subscriber_history_is_paginated_and_sorted_newest_first(): void
    {
        /** @var NotificationBatch $batch */
        $batch = NotificationBatch::factory()->create();

        /** @var Notification $oldest */
        $oldest = Notification::factory()->for($batch, 'batch')->create([
            'recipient_id' => 'subscriber-1',
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);

        /** @var Notification $newest */
        $newest = Notification::factory()->for($batch, 'batch')->create([
            'recipient_id' => 'subscriber-1',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        Notification::factory()->for($batch, 'batch')->create([
            'recipient_id' => 'subscriber-1',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);

        $response = $this->getJson(route('api.v1.subscribers.notifications.index', [
            'recipientId' => 'subscriber-1',
            'per_page' => 2,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->assertNotSame($oldest->id, $response->json('data.0.id'));
    }
}
