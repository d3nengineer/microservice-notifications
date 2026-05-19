<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\NotificationBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin NotificationBatch
 */
#[OA\Schema(
    schema: 'NotificationBatchResource',
    required: ['batch_id', 'notifications_count', 'status'],
    properties: [
        new OA\Property(property: 'batch_id', type: 'integer', example: 1),
        new OA\Property(property: 'notifications_count', type: 'integer', example: 2),
        new OA\Property(property: 'status', ref: '#/components/schemas/NotificationStatus'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NotificationBatchResponse',
    required: ['data'],
    properties: [
        new OA\Property(property: 'data', ref: '#/components/schemas/NotificationBatchResource'),
    ],
    type: 'object',
)]
class NotificationBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'batch_id' => $this->id,
            'notifications_count' => $this->notifications_count ?? $this->notifications()->count(),
            'status' => $this->status->value,
        ];
    }
}
