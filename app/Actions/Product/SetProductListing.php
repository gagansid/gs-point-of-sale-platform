<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mengatur outlet tempat produk dijual (ADR 0011 / Q42). Hanya outlet yang dipegang $by yang diubah;
 * outlet lain (termasuk ID asing/tenant lain di kiriman) tidak tersentuh. Tanpa $by = semua outlet bisnis.
 */
final class SetProductListing
{
    /**
     * @param  list<string>  $listedOutletIds  outlet yang dicentang "Dijual di outlet"
     */
    public function handle(Product $product, array $listedOutletIds, ?User $by = null): void
    {
        $manageable = $by?->outletIds() ?? Outlet::query()->pluck('id')->values()->all();

        DB::transaction(function () use ($product, $listedOutletIds, $manageable): void {
            foreach ($manageable as $outletId) {
                $stock = ProductStock::for($product->id, $outletId, lock: true);
                $stock->is_listed = in_array($outletId, $listedOutletIds, true);
                $stock->save();
            }
        });
    }
}
