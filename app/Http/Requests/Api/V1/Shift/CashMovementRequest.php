<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Shift;

use App\Enums\CashMovementReason;
use App\Enums\CashMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kas masuk/keluar (ADR 0017). Kas keluar di atas batas outlet: izin shift.cash_out_over_limit atau
 * PIN approver (diputuskan RecordCashMovement).
 */
final class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shift.operate') ?? false;
    }

    /** @return array<string, mixed> */
    public static function fieldRules(): array
    {
        return [
            'type' => ['required', Rule::enum(CashMovementType::class)],
            // Retur dibuat sistem (ADR 0018), tidak bisa dikirim manual
            'reason' => ['required', Rule::in(array_map(fn (CashMovementReason $r): string => $r->value, CashMovementReason::manual()))],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'note' => ['nullable', 'required_if:reason,'.CashMovementReason::Other->value, 'string', 'max:255'],
            ...ApprovalRules::rules(),
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
        return ['type' => 'jenis', 'reason' => 'alasan', 'amount' => 'nominal', 'note' => 'catatan', ...ApprovalRules::attributes()];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return self::fieldAttributes();
    }
}
