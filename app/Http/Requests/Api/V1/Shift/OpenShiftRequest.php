<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Buka shift (ADR 0017): opening_cash kosong = carry-over sisa laci shift sebelumnya di perangkat ini.
 * Angka berbeda dari carry-over = koreksi (alasan + izin shift.edit_opening_cash atau PIN approver).
 */
final class OpenShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shift.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'opening_cash' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'opening_cash_note' => ['nullable', 'string', 'max:255'],
            ...ApprovalRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['opening_cash' => 'kas awal', 'opening_cash_note' => 'alasan', ...ApprovalRules::attributes()];
    }
}
