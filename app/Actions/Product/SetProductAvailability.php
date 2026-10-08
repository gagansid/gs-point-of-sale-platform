<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Menandai menu habis/tersedia (mis. stok bahan habis hari ini) tanpa mengubah data produk.
 */
final class SetProductAvailability
{
    public function handle(Product $product, bool $available): Product
    {
        DB::transaction(fn () => $product->forceFill(['is_available' => $available])->save());

        return $product;
    }
}
