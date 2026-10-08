<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus produk (soft delete). Riwayat order tetap utuh karena memakai snapshot nama & harga.
 */
final class DeleteProduct
{
    public function handle(Product $product): void
    {
        DB::transaction(fn () => $product->delete());
    }
}
