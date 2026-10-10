<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Option;
use App\Models\Outlet;
use App\Models\OutletOption;
use Illuminate\Support\Facades\DB;

/**
 * Menandai opsi (mis. topping) habis/tersedia di satu outlet (ADR 0011 / Q43, permission
 * product.toggle_available). Outlet lain tidak terpengaruh.
 */
final class SetOptionAvailability
{
    public function handle(Option $option, Outlet $outlet, bool $available): void
    {
        DB::transaction(function () use ($option, $outlet, $available): void {
            // Kunci unik (outlet_id, option_id) mencegah baris ganda saat dipanggil bersamaan
            OutletOption::query()->updateOrCreate(
                ['outlet_id' => $outlet->id, 'option_id' => $option->id],
                ['is_available' => $available],
            );
        });
    }
}
