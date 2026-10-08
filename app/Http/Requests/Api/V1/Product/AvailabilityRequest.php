<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Product;

use Illuminate\Foundation\Http\FormRequest;

final class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product.toggle_available') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_available' => ['required', 'boolean']];
    }
}
