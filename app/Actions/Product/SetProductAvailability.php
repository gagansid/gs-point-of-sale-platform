<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menandai menu habis/tersedia di satu outlet (mis. stok bahan habis hari ini) tanpa mengubah
 * data produk. Outlet lain tidak terpengaruh (ADR 0010).
 *
 * Setiap perubahan tercatat di Riwayat perubahan (Q58, Q115): pelaku = user login (guard aktif:
 * dashboard, API, atau kasir web), outlet, produk, nilai lama → baru. Menandai ulang dengan nilai
 * yang sama tidak menyimpan apa pun dan tidak membuat log.
 */
final class SetProductAvailability
{
    public const LOG_NAME = 'ProductStock';

    public const LOG_EVENT = 'availability';

    public function handle(Product $product, Outlet $outlet, bool $available): Product
    {
        DB::transaction(function () use ($product, $outlet, $available): void {
            // Dicek di bawah lock agar dua perangkat yang menandai bersamaan tidak mencatat dua kali
            $stock = ProductStock::for($product->id, $outlet->id, lock: true);
            $previous = $stock->is_available;

            if ($previous === $available) {
                return;
            }

            $stock->is_available = $available;
            $stock->save();

            $this->log($stock, $product, $outlet, $previous, $available);
        });

        return $product->loadOutletState($outlet->id);
    }

    private function log(ProductStock $stock, Product $product, Outlet $outlet, bool $previous, bool $available): void
    {
        $actor = auth()->user();
        $description = sprintf('%s ditandai %s di %s', $product->name, $available ? 'tersedia' : 'habis', $outlet->name)
            .($actor instanceof User ? ' oleh '.$actor->name : '');

        activity(self::LOG_NAME)
            ->performedOn($stock)
            ->event(self::LOG_EVENT)
            ->withProperties([
                'old' => ['is_available' => $previous],
                'attributes' => ['is_available' => $available],
                // Nama disimpan agar log tetap terbaca walau produk/outlet kelak diganti nama atau dihapus
                'product_id' => $product->id,
                'product' => $product->name,
                'outlet_id' => $outlet->id,
                'outlet' => $outlet->name,
            ])
            ->log($description);
    }
}
