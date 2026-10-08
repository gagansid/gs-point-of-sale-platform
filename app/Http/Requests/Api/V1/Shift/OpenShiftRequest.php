<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

use Illuminate\Foundation\Http\FormRequest;

final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shift.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['opening_cash' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['opening_cash' => 'kas awal'];
    }
}
