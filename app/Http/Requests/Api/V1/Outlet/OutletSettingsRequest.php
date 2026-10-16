<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Outlet;

use App\Actions\Outlet\UpdateOutletSettings;
use App\Support\Timezones;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OutletSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('outlet.settings') ?? false;
    }

    /**
     * Aturan bersama API & form dashboard. Di API semua field "sometimes": yang tidak dikirim
     * memakai nilai lama (digabung di controller).
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'name' => ['string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'timezone' => ['string', Rule::in(array_keys(Timezones::OPTIONS))],
            'tax_rate' => ['decimal:0,2', 'min:0', 'max:100'],
            'tax_inclusive' => ['boolean'],
            'service_charge_rate' => ['decimal:0,2', 'min:0', 'max:100'],
            'rounding' => ['integer', Rule::in(UpdateOutletSettings::ROUNDING_OPTIONS)],
            'receipt_header' => ['nullable', 'string', 'max:500'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],
            'receipt_show_rounding' => ['boolean'],
            'discount_limits' => ['array:cashier,supervisor'],
            // decimal (bukan numeric): "1e-7" lolos numeric lalu merusak bccomp di PriceOrder (GSPOS-BUG-002)
            'discount_limits.cashier' => ['required_with:discount_limits', 'decimal:0,2', 'min:0', 'max:100'],
            'discount_limits.supervisor' => ['required_with:discount_limits', 'decimal:0,2', 'min:0', 'max:100'],
            // Q56: 0 / kosong = tanpa target; batas DECIMAL(15,2)
            'daily_sales_target' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'monthly_sales_target' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            // ADR 0017: laci kasir
            'cash_float' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'auto_lock_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'cash_out_approval_limit' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            // ADR 0018: 0 = retur tidak diizinkan
            'refund_max_days' => ['integer', 'min:0', 'max:365'],
        ];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return collect(self::fieldRules())
            ->map(fn (array $rules, string $field): array => str_contains($field, '.') ? $rules : ['sometimes', ...$rules])
            ->all();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama outlet',
            'address' => 'alamat',
            'timezone' => 'zona waktu',
            'tax_rate' => 'pajak',
            'service_charge_rate' => 'service charge',
            'rounding' => 'pembulatan',
            'receipt_header' => 'header struk',
            'receipt_footer' => 'footer struk',
            'receipt_show_rounding' => 'tampilkan pembulatan di struk',
            'discount_limits.cashier' => 'batas diskon kasir',
            'discount_limits.supervisor' => 'batas diskon supervisor',
            'daily_sales_target' => 'target omzet harian',
            'monthly_sales_target' => 'target omzet bulanan',
            'cash_float' => 'modal laci tetap',
            'auto_lock_minutes' => 'kunci layar otomatis',
            'cash_out_approval_limit' => 'batas kas keluar tanpa PIN',
            'refund_max_days' => 'batas hari retur',
        ];
    }
}
