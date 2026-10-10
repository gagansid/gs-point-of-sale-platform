<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\DB;

/**
 * Menandai menu habis/tersedia di satu outlet (mis. stok bahan habis hari ini) tanpa mengubah
 * data produk. Outlet lain tidak terpengaruh (ADR 0010).
 */
final class SetProductAvailability
{
    public function handle(Product $product, Outlet $outlet, bool $available): Product
    {
        DB::transaction(function () use ($product, $outlet, $available): void {
            $stock = ProductStock::for($product->id, $outlet->id, lock: true);
            $stock->is_available = $available;
            $stock->save();
        });

        return $product->loadOutletState($outlet->id);
    }
}
