<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateNotificationBatch;
use App\DTO\CreateNotificationBatchDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class NotificationBatchController extends Controller
{
    #[OA\Post(
        path: '/notification-batches',
        operationId: 'createNotificationBatch',
        summary: 'Create a notification batch',
        parameters: [
            new OA\Parameter(
                name: 'Idempotency-Key',
                in: 'header',
                required: true,
                schema: new OA\Schema(type: 'string', maxLength: 255),
                example: 'request-2026-05-18-001',
            ),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreNotificationBatchRequest'),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Notification batch created.',
                content: new OA\JsonContent(ref: '#/components/schemas/NotificationBatchResponse'),
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
            new OA\Response(
                response: 500,
                description: 'Server error.',
                content: new OA\JsonContent(ref: '#/components/schemas/GenericError'),
            ),
        ],
    )]
    public function store(StoreNotificationBatchRequest $request, CreateNotificationBatch $createNotificationBatch): JsonResponse
    {
        /** @var array{idempotency_key: string, channel: string, message: string, recipient_ids: list<string>, priority: string} $validated */
        $validated = $request->validated();
        $data = CreateNotificationBatchDTO::fromArray($validated);

        $batch = $createNotificationBatch($data);

        return (new NotificationBatchResource($batch))
            ->response()
            ->setStatusCode(201);
    }
}
