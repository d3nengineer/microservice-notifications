<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Parameter(
    parameter: 'SubscriberRecipientId',
    name: 'recipientId',
    description: 'External subscriber identifier.',
    in: 'path',
    required: true,
    schema: new OA\Schema(type: 'string'),
    example: 'subscriber-1',
)]
#[OA\Parameter(
    parameter: 'NotificationStatusFilter',
    name: 'status',
    in: 'query',
    required: false,
    schema: new OA\Schema(ref: '#/components/schemas/NotificationStatus'),
)]
#[OA\Parameter(
    parameter: 'NotificationChannelFilter',
    name: 'channel',
    in: 'query',
    required: false,
    schema: new OA\Schema(ref: '#/components/schemas/NotificationChannel'),
)]
#[OA\Parameter(
    parameter: 'PaginationPage',
    name: 'page',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'integer', minimum: 1),
    example: 1,
)]
#[OA\Parameter(
    parameter: 'PaginationPerPage',
    name: 'per_page',
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'integer', maximum: 100, minimum: 1, default: 15),
    example: 15,
)]
class ListSubscriberNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(NotificationStatus::class)],
            'channel' => ['sometimes', 'string', Rule::enum(NotificationChannel::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->integer('per_page', 15);
    }
}
