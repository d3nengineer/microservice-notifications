<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaginatedNotifications',
    required: ['data', 'links', 'meta'],
    properties: [
        new OA\Property(
            property: 'data',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/NotificationResource'),
        ),
        new OA\Property(
            property: 'links',
            required: ['first', 'last', 'prev', 'next'],
            properties: [
                new OA\Property(property: 'first', type: ['string', 'null'], format: 'uri'),
                new OA\Property(property: 'last', type: ['string', 'null'], format: 'uri'),
                new OA\Property(property: 'prev', type: ['string', 'null'], format: 'uri'),
                new OA\Property(property: 'next', type: ['string', 'null'], format: 'uri'),
            ],
            type: 'object',
        ),
        new OA\Property(
            property: 'meta',
            properties: [
                new OA\Property(property: 'current_page', type: 'integer'),
                new OA\Property(property: 'from', type: ['integer', 'null']),
                new OA\Property(property: 'last_page', type: 'integer'),
                new OA\Property(property: 'path', type: 'string'),
                new OA\Property(property: 'per_page', type: 'integer'),
                new OA\Property(property: 'to', type: ['integer', 'null']),
                new OA\Property(property: 'total', type: 'integer'),
            ],
            type: 'object',
        ),
    ],
    type: 'object',
)]
class NotificationCollection extends ResourceCollection
{
    public $collects = NotificationResource::class;
}
