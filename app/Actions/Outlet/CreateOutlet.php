<?php

declare(strict_types=1);

namespace App\Actions\Outlet;

use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menambah outlet (Pengaturan → Outlet, permission outlet.manage — ADR 0010, Q36).
 * Setelan pajak/service/pembulatan/batas diskon disalin dari outlet pertama agar seragam;
 * kode outlet unik per bisnis dan tidak bisa diubah (awalan nomor order).
 * Owner otomatis memegang outlet baru; karyawan lain ditugaskan lewat menu Karyawan.
 */
final class CreateOutlet
{
    /**
     * @throws BusinessException OUTLET_LIMIT_REACHED / VALIDATION_ERROR
     */
    public function handle(string $code, string $name, ?string $address, string $timezone): Outlet
    {
        $code = Str::upper(trim($code));

        return DB::transaction(function () use ($code, $name, $address, $timezone): Outlet {
            // Kunci baris tenant: dua penambahan bersamaan tidak melewati batas outlet
            $tenant = Tenant::query()->lockForUpdate()->findOrFail(TenantContext::idOrFail());
            self::ensureWithinLimit($tenant);

            if (Outlet::query()->withTrashed()->where('code', $code)->exists()) {
                throw BusinessException::of(ErrorCode::ValidationError, details: ['code' => ['Kode outlet sudah dipakai']]);
            }

            $template = Outlet::query()->orderBy('created_at')->first();

            return Outlet::query()->create([
                'code' => $code,
                'name' => trim($name),
                'address' => filled($address) ? trim($address) : null,
                'timezone' => $timezone,
                'tax_rate' => $template->tax_rate ?? config('pos.outlet_defaults.tax_rate'),
                'service_charge_rate' => $template->service_charge_rate ?? config('pos.outlet_defaults.service_charge_rate'),
                'tax_inclusive' => $template->tax_inclusive ?? config('pos.outlet_defaults.tax_inclusive'),
                'rounding' => $template->rounding ?? config('pos.outlet_defaults.rounding'),
                'receipt_footer' => $template?->receipt_footer,
                'discount_limits' => $template->discount_limits ?? config('pos.outlet_defaults.discount_limits'),
                'is_active' => true,
            ]);
        });
    }

    /**
     * @throws BusinessException OUTLET_LIMIT_REACHED
     */
    public static function ensureWithinLimit(Tenant $tenant): void
    {
        $limit = $tenant->outletLimit();

        if ($limit !== null && Outlet::forTenant($tenant->id)->active()->count() >= $limit) {
            throw BusinessException::of(ErrorCode::OutletLimitReached, details: ['max_outlets' => $limit]);
        }
    }
}
