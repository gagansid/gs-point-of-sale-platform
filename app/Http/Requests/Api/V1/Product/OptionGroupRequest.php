<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;

final class OptionGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'min_select' => ['required', 'integer', 'min:0', 'max:20'],
            'max_select' => ['required', 'integer', 'min:1', 'max:20', 'gte:min_select'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['required', 'array', 'min:1', 'max:50'],
            'options.*.id' => ['nullable', 'uuid'],
            'options.*.name' => ['required', 'string', 'max:100', 'distinct'],
            'options.*.price_delta' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama grup',
            'min_select' => 'minimal pilihan',
            'max_select' => 'maksimal pilihan',
            'options' => 'opsi',
            'options.*.name' => 'nama opsi',
            'options.*.price_delta' => 'tambahan harga',
        ];
    }
}
