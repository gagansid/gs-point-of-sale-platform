<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Addon;
use App\Models\Tenant;
use App\Models\TenantAddon;
use App\Support\Edition;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * Atur add-on bisnis langsung oleh super admin, tanpa tagihan (ADR 0025 C1), seperti Atur paket. Isi = keadaan akhir:
 * {addon_id: jumlah}; 0/kosong = dilepas saat itu juga. Syarat: edisi SaaS & bisnis berpaket (tanpa paket = semua fitur,
 * tanpa batas). Add-on fitur maks. 1 unit dan tidak boleh fitur yang sudah termasuk paket. Mengurangi kuota di bawah
 * pemakaian tidak menonaktifkan data (sama dengan downgrade).
 */
final class SetTenantAddons
{
    private const MAX_UNITS = 999;

    /**
     * @param  array<string, int|string|bool|null>  $quantities
     *
     * @throws BusinessException
     */
    public function handle(Tenant $tenant, array $quantities): Tenant
    {
        if (! Edition::isSaas()) {
            throw BusinessException::of(ErrorCode::Forbidden, 'Edisi jual putus tidak memakai add-on');
        }

        $wanted = collect($quantities)->map(fn (mixed $qty): int => (int) $qty)->filter(fn (int $qty): bool => $qty > 0);

        return DB::transaction(function () use ($tenant, $wanted): Tenant {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $owned = TenantAddon::forTenant($tenant->id)->get()->keyBy('addon_id');

            if ($wanted->isNotEmpty() && $tenant->plan_id === null) {
                throw self::invalid('Bisnis tanpa paket sudah mendapat semua fitur tanpa batas. Atur paket dulu.');
            }

            $addons = Addon::query()->whereIn('id', $wanted->keys())->get()->keyBy('id');
            $planFeatures = $tenant->plan->features ?? [];

            foreach ($wanted as $addonId => $qty) {
                $addon = $addons->get($addonId) ?? throw self::invalid('Add-on tidak ditemukan');

                // Add-on nonaktif hanya boleh dipertahankan, tidak ditambahkan baru
                if (! $addon->is_active && ! $owned->has($addonId)) {
                    throw self::invalid("Add-on {$addon->name} tidak aktif");
                }
                if (! $addon->isQuota() && $qty > 1) {
                    throw self::invalid("Add-on fitur {$addon->name} hanya 1 unit");
                }
                if (! $addon->isQuota() && in_array($addon->feature_key, $planFeatures, true)) {
                    throw self::invalid("Fitur {$addon->name} sudah termasuk paket {$tenant->plan?->name}");
                }
                if ($qty > self::MAX_UNITS) {
                    throw self::invalid('Maksimal '.self::MAX_UNITS.' unit per add-on');
                }

                $row = $owned->get($addonId) ?? new TenantAddon(['addon_id' => $addonId]);
                $row->tenant_id = $tenant->id;
                $row->quantity = $qty;
                // Diatur admin = berlaku penuh (jadwal berhenti dibatalkan)
                $row->forceFill(['status' => TenantAddon::ACTIVE, 'cancel_at' => null])->save();
            }

            TenantAddon::forTenant($tenant->id)->whereNotIn('addon_id', $wanted->keys())->delete();
            Features::flush();

            return $tenant->unsetRelation('addons');
        });
    }

    private static function invalid(string $message): BusinessException
    {
        return BusinessException::of(ErrorCode::ValidationError, $message, ['addons' => [$message]]);
    }
}
