<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Tutup shift di perangkat ini (shift.operate, siapa saja yang bertugas, ADR 0017) atau tutup paksa
 * (shift.force_close, catatan wajib).
 */
final class CloseShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isForce() ? 'shift.force_close' : 'shift.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actual_cash' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            // ADR 0017: kosong = kas aktual − modal laci tetap outlet (atau 0)
            'cash_deposited' => ['nullable', 'decimal:0,2', 'min:0', 'lte:actual_cash', 'max:9999999999999.99'],
            'note' => [$this->isForce() ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['actual_cash' => 'kas aktual', 'cash_deposited' => 'disetor', 'note' => 'catatan'];
    }

    public function isForce(): bool
    {
        return $this->routeIs('api.v1.shifts.force-close');
    }
}
