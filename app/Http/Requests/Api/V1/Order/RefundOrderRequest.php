<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Retur sebagian (ADR 0018). Kasir lolos di sini; izin akhir diputuskan VerifyApproval (order.refund atau PIN). */
final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public static function fieldRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:9999'],
            'reason' => ['required', 'string', 'max:255'],
            'method' => ['required', Rule::enum(RefundMethod::class)],
            'restock' => ['sometimes', 'boolean'],
            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'string', 'digits:6', 'required_with:approver_user_id'],
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['id' => ['required', 'uuid'], ...self::fieldRules()];
    }

    /** @return array<string, string> */
    public static function fieldAttributes(): array
    {
        return [
            'items' => 'item', 'items.*.order_item_id' => 'item', 'items.*.qty' => 'qty', 'reason' => 'alasan', 'method' => 'cara pengembalian',
            'restock' => 'kembalikan ke stok', 'approver_user_id' => 'approver', 'approver_pin' => 'PIN approver',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return self::fieldAttributes();
    }
}
