<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTO\ListSubscriberNotificationsDTO;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ListSubscriberNotifications
{
    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function __invoke(ListSubscriberNotificationsDTO $filters): LengthAwarePaginator
    {
        Log::info('Subscriber notification history requested.', [
            'recipient_id' => $filters->recipientId,
            'filters' => [
                'status' => $filters->status?->value,
                'channel' => $filters->channel?->value,
            ],
            'page' => $filters->page,
            'per_page' => $filters->perPage,
        ]);

        return Notification::query()
            ->with('batch:id')
            ->where('recipient_id', $filters->recipientId)
            ->when($filters->status, fn (Builder $query): Builder => $query->where('status', $filters->status))
            ->when($filters->channel, fn (Builder $query): Builder => $query->where('channel', $filters->channel))
            ->latest()
            ->paginate($filters->perPage, ['*'], 'page', $filters->page)
            ->withQueryString();
    }
}
