<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Plan;
use App\Support\Features;
use App\Support\Money;
use App\Support\Quotas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah paket langganan dari panel /admin (ADR 0024, Q108). Fitur harus ada di master data features (ADR 0025).
 * Periode opsional (available_from/until, UTC): di luar periode paket tidak bisa dipilih owner untuk tagihan baru.
 */
final class SavePlan
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(?Plan $plan, array $data): Plan
    {
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', Rule::unique('plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_monthly' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'price_yearly' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            ...Quotas::rules('limits'),
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::exists('features', 'key')],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            // Periode bisa dipilih owner (promo); kosong = tanpa batas
            'available_from' => ['nullable', 'date'],
            'available_until' => ['nullable', 'date', ...(filled($data['available_from'] ?? null) ? ['after:available_from'] : [])],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [], [
            'code' => 'kode', 'name' => 'nama', 'price_monthly' => 'harga bulanan', 'price_yearly' => 'harga tahunan',
            'available_from' => 'mulai', 'available_until' => 'berakhir',
        ])->validate();

        $plan ??= new Plan;
        $plan->fill([
            ...$v,
            'price_monthly' => Money::of((string) $v['price_monthly']),
            'price_yearly' => Money::of((string) $v['price_yearly']),
            'limits' => Quotas::normalize($v['limits'] ?? null),
            'features' => array_values(array_unique(array_map('strval', (array) ($v['features'] ?? [])))),
            'is_active' => (bool) ($v['is_active'] ?? true),
            'is_public' => (bool) ($v['is_public'] ?? true),
            'available_from' => filled($v['available_from'] ?? null) ? CarbonImmutable::parse((string) $v['available_from']) : null,
            'available_until' => filled($v['available_until'] ?? null) ? CarbonImmutable::parse((string) $v['available_until']) : null,
            'sort_order' => (int) ($v['sort_order'] ?? 0),
        ])->save();

        Features::flush();

        return $plan;
    }
}
