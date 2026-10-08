<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Order;

use Illuminate\Foundation\Http\FormRequest;

final class VoidOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Kasir lolos di sini; izin akhir diputuskan VerifyApproval (order.void atau PIN approver)
        return $this->user()?->can('order.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'approver_user_id' => ['nullable', 'uuid', 'required_with:approver_pin'],
            'approver_pin' => ['nullable', 'string', 'digits:6', 'required_with:approver_user_id'],
        ];
    }
}
