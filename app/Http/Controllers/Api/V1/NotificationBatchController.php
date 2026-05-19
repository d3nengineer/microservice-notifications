<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateNotificationBatch;
use App\DTO\CreateNotificationBatchDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;
use Throwable;

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

        Log::info('Notification batch creation requested.', [
            'idempotency_key' => $data->idempotencyKey,
            'channel' => $data->channel->value,
            'priority' => $data->priority->value,
            'recipient_count' => count($data->recipientIds),
        ]);

        try {
            $batch = $createNotificationBatch($data);
        } catch (Throwable $exception) {
            Log::error('Notification batch creation failed.', [
                'idempotency_key' => $data->idempotencyKey,
                'channel' => $data->channel->value,
                'priority' => $data->priority->value,
                'recipient_count' => count($data->recipientIds),
                'exception' => $exception::class,
                'exception_code' => $exception->getCode(),
            ]);

            throw $exception;
        }

        Log::info('Notification batch created.', [
            'batch_id' => $batch->id,
            'notifications_count' => $batch->notifications_count,
        ]);

        return (new NotificationBatchResource($batch))
            ->response()
            ->setStatusCode(201);
    }
}
