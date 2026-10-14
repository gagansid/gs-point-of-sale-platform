<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

/** Pecah open bill (ADR 0019). */
final class SplitOpenBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public static function fieldRules(): array
    {
        return [
            'new_order_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.order_item_id' => ['required', 'uuid'],
            'items.*.qty' => ['required', 'integer', 'min:0', 'max:9999'],
            'table_label' => ['nullable', 'string', 'max:50'],
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::fieldRules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['items' => 'item', 'items.*.order_item_id' => 'item', 'items.*.qty' => 'qty', 'table_label' => 'meja'];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    public static function moves(array $data): array
    {
        $moves = [];
        foreach ((array) ($data['items'] ?? []) as $row) {
            $id = (string) ($row['order_item_id'] ?? '');
            $moves[$id] = ($moves[$id] ?? 0) + (int) ($row['qty'] ?? 0);
        }

        return $moves;
    }
}
