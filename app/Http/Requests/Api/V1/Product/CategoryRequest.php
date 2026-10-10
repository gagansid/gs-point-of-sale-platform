<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;

final class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Opsional saat membuat: idempotency key dari aplikasi (UUID v7)
            'id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['name' => 'nama kategori'];
    }
}
