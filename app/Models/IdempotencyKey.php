<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $key
 * @property string $payload_hash
 * @property int|null $notification_batch_id
 * @property array<string, mixed>|null $response_body
 * @property int|null $response_status
 */
#[Fillable(['key', 'payload_hash', 'notification_batch_id', 'response_body', 'response_status'])]
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function notificationBatch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class);
    }
}
