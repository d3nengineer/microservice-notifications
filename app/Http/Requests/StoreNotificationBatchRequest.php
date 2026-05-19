<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreNotificationBatchRequest',
    required: ['channel', 'message', 'recipient_ids', 'priority'],
    properties: [
        new OA\Property(property: 'channel', ref: '#/components/schemas/NotificationChannel'),
        new OA\Property(property: 'message', type: 'string', maxLength: 5000, minLength: 1, example: 'Your verification code is 1234'),
        new OA\Property(
            property: 'recipient_ids',
            type: 'array',
            maxItems: 1000,
            minItems: 1,
            uniqueItems: true,
            items: new OA\Items(type: 'string', maxLength: 255),
            example: ['subscriber-1', 'subscriber-2'],
        ),
        new OA\Property(property: 'priority', ref: '#/components/schemas/NotificationPriority'),
    ],
    type: 'object',
)]
class StoreNotificationBatchRequest extends FormRequest
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
            'idempotency_key' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'message' => ['required', 'string', 'max:5000'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'recipient_ids.*' => ['required', 'string', 'distinct', 'max:255'],
            'priority' => ['required', Rule::enum(NotificationPriority::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }
}
