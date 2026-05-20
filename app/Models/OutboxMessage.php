<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OutboxMessageStatus;
use Database\Factories\OutboxMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string $topic
 * @property array<string, mixed> $payload
 * @property OutboxMessageStatus $status
 * @property int $attempts
 * @property Carbon $available_at
 * @property Carbon|null $published_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<self> pendingDue(?Carbon $now = null)
 */
#[Fillable(['aggregate_type', 'aggregate_id', 'topic', 'payload', 'status', 'attempts', 'available_at', 'published_at', 'last_error'])]
class OutboxMessage extends Model
{
    /** @use HasFactory<OutboxMessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxMessageStatus::class,
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingDue(Builder $query, ?Carbon $now = null): Builder
    {
        return $query
            ->where('status', OutboxMessageStatus::Pending->value)
            ->where('available_at', '<=', $now ?? now());
    }
}
