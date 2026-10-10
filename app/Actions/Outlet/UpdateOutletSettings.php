<?php

declare(strict_types=1);

namespace App\Actions\Outlet;

use App\Actions\Outlet\Data\OutletSettingsData;
use App\Models\Outlet;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Profil outlet (SPEC: GET/PUT /outlet). Order lama tidak berubah: setiap order
 * menyimpan tarif pajak/service/pembulatan miliknya sendiri saat transaksi.
 */
final class UpdateOutletSettings
{
    /** Pilihan pembulatan grand total (Rp). 0 = tanpa pembulatan. */
    public const ROUNDING_OPTIONS = [0, 100, 500, 1000];

    public function handle(Outlet $outlet, OutletSettingsData $data): Outlet
    {
        DB::transaction(fn () => $outlet->fill([
            'name' => $data->name,
            'address' => $data->address,
            'timezone' => $data->timezone,
            'tax_rate' => $data->taxRate,
            'tax_inclusive' => $data->taxInclusive,
            'service_charge_rate' => $data->serviceChargeRate,
            'rounding' => $data->rounding,
            'receipt_header' => $data->receiptHeader,
            'receipt_footer' => $data->receiptFooter,
            'discount_limits' => $data->discountLimits,
        ])->save());

        return $outlet->refresh();
    }
}
