<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderIndexRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            // pos / self_order (ADR 0014)
            'channel' => ['nullable', Rule::enum(OrderChannel::class)],
            'shift_id' => ['nullable', 'uuid'],
            // Antrean pengantaran (ADR 0027): daftar dipisah koma, mis. queued,preparing,ready
            'fulfillment' => ['nullable', 'string', 'max:60', 'regex:/^(queued|preparing|ready|served|auto_completed)(,(queued|preparing|ready|served|auto_completed))*$/'],
            'search' => ['nullable', 'string', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
