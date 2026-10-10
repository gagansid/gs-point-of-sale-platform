<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Tandai/lepas produk favorit outlet. Produk favorit ditampilkan di tab "Favorit" aplikasi kasir.
 */
final class SetProductFavorite
{
    public function handle(Product $product, bool $favorite): Product
    {
        DB::transaction(fn () => $product->forceFill(['is_favorite' => $favorite])->save());

        return $product;
    }
}
