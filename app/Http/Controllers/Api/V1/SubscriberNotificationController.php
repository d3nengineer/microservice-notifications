<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ListSubscriberNotifications;
use App\DTO\ListSubscriberNotificationsDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSubscriberNotificationsRequest;
use App\Http\Resources\NotificationCollection;
use OpenApi\Attributes as OA;

class SubscriberNotificationController extends Controller
{
    #[OA\Get(
        path: '/subscribers/{recipientId}/notifications',
        operationId: 'listSubscriberNotifications',
        summary: 'List subscriber notification history',
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/SubscriberRecipientId'),
            new OA\Parameter(ref: '#/components/parameters/NotificationStatusFilter'),
            new OA\Parameter(ref: '#/components/parameters/NotificationChannelFilter'),
            new OA\Parameter(ref: '#/components/parameters/PaginationPage'),
            new OA\Parameter(ref: '#/components/parameters/PaginationPerPage'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated notification history.',
                content: new OA\JsonContent(ref: '#/components/schemas/PaginatedNotifications'),
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
    public function index(
        ListSubscriberNotificationsRequest $request,
        ListSubscriberNotifications $listSubscriberNotifications,
        string $recipientId,
    ): NotificationCollection {
        /** @var array{status?: string, channel?: string, page?: int, per_page?: int} $filters */
        $filters = $request->validated();
        $data = ListSubscriberNotificationsDTO::fromArray($recipientId, $filters, $request->perPage());

        $notifications = $listSubscriberNotifications($data);

        return new NotificationCollection($notifications);
    }
}
