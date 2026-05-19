<?php

declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Notification Service API',
    description: 'Versioned JSON API for creating notification batches and reading subscriber notification history.',
)]
#[OA\Server(
    url: '/api/v1',
    description: 'API v1',
)]
#[OA\Schema(schema: 'NotificationChannel', type: 'string', enum: ['email', 'sms'])]
#[OA\Schema(schema: 'NotificationPriority', type: 'string', enum: ['high', 'normal', 'low'])]
#[OA\Schema(schema: 'NotificationStatus', type: 'string', enum: ['queued', 'sent', 'delivered', 'dropped'])]
#[OA\Schema(
    schema: 'ValidationError',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(
                type: 'array',
                items: new OA\Items(type: 'string'),
            ),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'GenericError',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Server Error'),
    ],
    type: 'object',
)]
final class OpenApiDocument {}
