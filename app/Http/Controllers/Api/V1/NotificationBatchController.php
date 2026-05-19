<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateNotificationBatch;
use App\DTO\CreateNotificationBatchDTO;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\IdempotencyLockTimeoutException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreNotificationBatchRequest;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

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
                response: 409,
                description: 'Idempotency key conflict.',
                content: new OA\JsonContent(ref: '#/components/schemas/GenericError'),
            ),
            new OA\Response(
                response: 423,
                description: 'Idempotency key is currently locked.',
                content: new OA\JsonContent(ref: '#/components/schemas/GenericError'),
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

        try {
            $result = $createNotificationBatch($data, $request);
        } catch (IdempotencyConflictException) {
            return response()->json([
                'message' => 'The Idempotency-Key has already been used with a different payload.',
                'error' => 'idempotency_key_conflict',
            ], Response::HTTP_CONFLICT);
        } catch (IdempotencyLockTimeoutException) {
            return response()->json([
                'message' => 'Another request is already processing this Idempotency-Key.',
                'error' => 'idempotency_key_locked',
            ], Response::HTTP_LOCKED);
        }

        return response()->json($result->responseBody, $result->responseStatus);
    }
}
