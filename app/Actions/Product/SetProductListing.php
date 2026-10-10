<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Mengatur outlet tempat produk dijual (ADR 0011 / Q42). Hanya outlet aktif yang dipegang $by yang diubah
 * (= pilihan di form); outlet lain, outlet nonaktif, dan ID asing/tenant lain di kiriman tidak tersentuh.
 * Tanpa $by = semua outlet aktif bisnis.
 */
final class SetProductListing
{
    /**
     * @param  list<string>  $listedOutletIds  outlet yang dicentang "Dijual di outlet"
     */
    public function handle(Product $product, array $listedOutletIds, ?User $by = null): void
    {
        $manageable = self::manageableOutlets($by)->pluck('id')->values()->all();

        DB::transaction(function () use ($product, $listedOutletIds, $manageable): void {
            foreach ($manageable as $outletId) {
                $stock = ProductStock::for($product->id, $outletId, lock: true);
                $stock->is_listed = in_array($outletId, $listedOutletIds, true);
                $stock->save();
            }
        });
    }

    /**
     * Outlet yang statusnya boleh diatur $by (juga sumber pilihan "Dijual di outlet" di form).
     *
     * @return Builder<Outlet>
     */
    public static function manageableOutlets(?User $by): Builder
    {
        return ($by?->accessibleOutlets() ?? Outlet::query()->orderBy('created_at'))->active();
    }

    /**
     * ID outlet (dari manageableOutlets) tempat produk dijual — nilai awal form ubah produk.
     *
     * @return list<string>
     */
    public static function listedOutletIds(Product $product, ?User $by): array
    {
        $unlisted = ProductStock::query()->where('product_id', $product->id)->where('is_listed', false)->pluck('outlet_id')->all();

        return self::manageableOutlets($by)->whereNotIn('id', $unlisted)->pluck('id')->values()->all();
    }
}
