<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\DiscountType;
use App\Enums\OrderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bentuk data checkout. Aturan bisnis (stok, opsi, batas diskon, saldo) diperiksa di CheckoutOrder.
 */
final class CheckoutRequest extends FormRequest
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
            'id' => ['required', 'uuid'],
            'order_type' => ['required', Rule::enum(OrderType::class)],
            'table_label' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:255'],

            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', Rule::enum(DiscountType::class)],
            'discount.value' => ['required_with:discount', ...$money, 'min:0', Rule::when($this->input('discount.type') === 'percent', ['max:100'])],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.option_ids' => ['sometimes', 'array', 'max:20'],
            // Tanpa 'distinct': rule itu membandingkan opsi LINTAS semua item (dua kopi Large = "ganda").
            // Duplikat dalam satu item dibuang di CheckoutData.
            'items.*.option_ids.*' => ['uuid'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            'items.*.discount' => ['nullable', ...$money, 'min:0'],

            'payments' => ['required', 'array', 'min:1', 'max:10'],
            'payments.*.id' => ['required', 'uuid', 'distinct'],
            'payments.*.payment_method_id' => ['required', 'uuid'],
            'payments.*.amount' => ['required', ...$money, 'gt:0'],
            'payments.*.tendered' => ['nullable', ...$money, 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:50'],

            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'string', 'digits:6', 'required_with:approver_user_id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'items' => 'item',
            'items.*.qty' => 'qty',
            'items.*.product_id' => 'produk',
            'payments.*.amount' => 'nominal',
            'payments.*.payment_method_id' => 'metode bayar',
            'discount.value' => 'nilai diskon',
        ];
    }
}
