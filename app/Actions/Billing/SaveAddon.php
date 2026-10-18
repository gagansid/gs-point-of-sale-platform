<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Addon;
use App\Models\TenantAddon;
use App\Support\Features;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Buat/ubah add-on dari panel /admin (ADR 0025 C1). Fitur/kuota harus ada di master data. Jenis & isi manfaat tidak
 * boleh diubah selama add-on dipakai bisnis (ubah harga/nama saja, atau buat add-on baru).
 */
final class SaveAddon
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(?Addon $addon, array $data): Addon
    {
        $v = Validator::make($data, [
            'code' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', Rule::unique('addons', 'code')->ignore($addon?->id)],
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(Addon::TYPES))],
            'feature_key' => ['nullable', 'required_if:type,'.Addon::TYPE_FEATURE, 'string', Rule::exists('features', 'key')],
            'quota_key' => ['nullable', 'required_if:type,'.Addon::TYPE_QUOTA, 'string', Rule::exists('quotas', 'key')],
            'quota_amount' => ['nullable', 'required_if:type,'.Addon::TYPE_QUOTA, 'integer', 'min:1', 'max:100000'],
            'price_monthly' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'price_yearly' => ['required', 'decimal:0,2', 'min:0', 'max:'.Money::MAX_PRICE],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], [], [
            'code' => 'kode', 'name' => 'nama', 'type' => 'jenis', 'feature_key' => 'fitur', 'quota_key' => 'kuota',
            'quota_amount' => 'tambahan per unit', 'price_monthly' => 'harga bulanan', 'price_yearly' => 'harga tahunan',
        ])->validate();

        $isQuota = $v['type'] === Addon::TYPE_QUOTA;
        $benefit = [
            'type' => $v['type'],
            'feature_key' => $isQuota ? null : $v['feature_key'],
            'quota_key' => $isQuota ? $v['quota_key'] : null,
            'quota_amount' => $isQuota ? (int) $v['quota_amount'] : null,
        ];

        return DB::transaction(function () use ($addon, $v, $benefit): Addon {
            if ($addon !== null && TenantAddon::allTenants()->where('addon_id', $addon->id)->exists()) {
                $changed = collect($benefit)->filter(fn (mixed $value, string $key): bool => $addon->getAttribute($key) !== $value);
                if ($changed->isNotEmpty()) {
                    throw ValidationException::withMessages(['type' => 'Add-on sudah dipakai bisnis: jenis & manfaat tidak bisa diubah. Buat add-on baru.']);
                }
            }

            $addon ??= new Addon;
            $addon->fill([
                ...$v,
                ...$benefit,
                'price_monthly' => Money::of((string) $v['price_monthly']),
                'price_yearly' => Money::of((string) $v['price_yearly']),
                'is_active' => (bool) ($v['is_active'] ?? true),
                'is_public' => (bool) ($v['is_public'] ?? true),
                'sort_order' => (int) ($v['sort_order'] ?? 0),
            ])->save();

            Features::flush();

            return $addon;
        });
    }
}
