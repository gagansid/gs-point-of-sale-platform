<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

final class AddPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['decimal:0,2', 'max:9999999999999.99', 'gt:0'];

        return [
            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.id' => ['required', 'uuid', 'distinct'],
            'payments.*.payment_method_id' => ['required', 'uuid'],
            'payments.*.amount' => ['required', ...$money],
            'payments.*.tendered' => ['nullable', ...$money],
            'payments.*.reference' => ['nullable', 'string', 'max:50'],
        ];
    }
}
