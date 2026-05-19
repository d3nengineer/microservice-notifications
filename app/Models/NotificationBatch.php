<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use Database\Factories\NotificationBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property NotificationChannel $channel
 * @property string $message
 * @property NotificationPriority $priority
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property NotificationStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int|null $notifications_count
 *
 * @method static self create(array<string, mixed> $attributes = [])
 */
#[Fillable(['channel', 'message', 'priority', 'idempotency_key', 'payload_hash', 'status'])]
class NotificationBatch extends Model
{
    /** @use HasFactory<NotificationBatchFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
        ];
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    /**
     * @return HasOne<IdempotencyKey, $this>
     */
    public function idempotencyKey(): HasOne
    {
        return $this->hasOne(IdempotencyKey::class);
    }
}
