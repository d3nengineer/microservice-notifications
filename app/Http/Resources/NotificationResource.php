<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin Notification
 */
#[OA\Schema(
    schema: 'NotificationResource',
    required: ['id', 'batch_id', 'recipient_id', 'channel', 'message', 'priority', 'status', 'created_at', 'updated_at'],
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 10),
        new OA\Property(property: 'batch_id', type: 'integer', example: 1),
        new OA\Property(property: 'recipient_id', type: 'string', example: 'subscriber-1'),
        new OA\Property(property: 'channel', ref: '#/components/schemas/NotificationChannel'),
        new OA\Property(property: 'message', type: 'string', example: 'Your verification code is 1234'),
        new OA\Property(property: 'priority', ref: '#/components/schemas/NotificationPriority'),
        new OA\Property(property: 'status', ref: '#/components/schemas/NotificationStatus'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->batch_id,
            'recipient_id' => $this->recipient_id,
            'channel' => $this->channel->value,
            'message' => $this->message,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
