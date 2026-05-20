<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * @property int $id
 * @property int $batch_id
 * @property string $recipient_id
 * @property NotificationChannel $channel
 * @property string $message
 * @property NotificationPriority $priority
 * @property NotificationStatus $status
 * @property string $deduplication_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['batch_id', 'recipient_id', 'channel', 'message', 'priority', 'status', 'deduplication_key'])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
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
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /**
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * @return HasMany<OutboxMessage, $this>
     */
    public function outboxMessages(): HasMany
    {
        /** @var HasMany<OutboxMessage, $this> $relation */
        $relation = $this->hasMany(OutboxMessage::class, 'aggregate_id');
        $relation->where('aggregate_type', self::class);

        return $relation;
    }

    public function canBeSent(): bool
    {
        $canBeSent = ! in_array($this->status, [
            NotificationStatus::Sent,
            NotificationStatus::Delivered,
            NotificationStatus::Dropped,
        ], true);

        if (! $canBeSent) {
            Log::info('Notification send skipped because status is final.', [
                'notification_id' => $this->id,
                'status' => $this->status->value,
                'deduplication_key_hash' => hash('sha256', $this->deduplication_key),
            ]);
        }

        return $canBeSent;
    }
}
