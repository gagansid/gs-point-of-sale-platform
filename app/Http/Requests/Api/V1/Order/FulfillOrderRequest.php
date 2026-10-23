<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Majukan tahap pengantaran pesanan meja (ADR 0027): preparing / ready / served. */
final class FulfillOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.fulfill') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['preparing', 'ready', 'served'])],
        ];
    }
}
