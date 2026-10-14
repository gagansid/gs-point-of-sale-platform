<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

use Illuminate\Foundation\Http\FormRequest;

/** Koreksi kas awal shift berjalan (ADR 0017); izin akhir diputuskan VerifyApproval. */
final class CorrectOpeningCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shift.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'note' => ['required', 'string', 'max:255'],
            ...ApprovalRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['opening_cash' => 'kas awal', 'note' => 'alasan', ...ApprovalRules::attributes()];
    }
}
