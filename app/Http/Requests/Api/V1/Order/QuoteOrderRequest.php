<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\DiscountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pratinjau total keranjang dari server (ADR 0022): field sama dengan checkout tanpa pembayaran. */
final class QuoteOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['decimal:0,2', 'max:9999999999999.99'];

        return [
            'member_id' => ['nullable', 'uuid'],
            'stamp_program_ids' => ['nullable', 'array', 'max:5'],
            'stamp_program_ids.*' => ['uuid', 'distinct'],
            'voucher_code' => ['nullable', 'string', 'max:30', 'prohibits:voucher_id'],
            'voucher_id' => ['nullable', 'uuid'],
            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', Rule::enum(DiscountType::class)],
            'discount.value' => ['required_with:discount', ...$money, 'min:0', Rule::when($this->input('discount.type') === 'percent', ['max:100'])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.option_ids' => ['sometimes', 'array', 'max:20'],
            'items.*.option_ids.*' => ['uuid'],
            'items.*.discount' => ['nullable', ...$money, 'min:0'],
        ];
    }
}
