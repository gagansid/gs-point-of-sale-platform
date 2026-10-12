<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Models\Product;
use App\Models\ProductStock;
use App\Support\Money;

/**
 * Modal rata-rata tertimbang (ADR 0015, Q80). `products.cost_price` berlaku per produk, jadi stok dijumlahkan dari
 * semua outlet (stok minus = 0). Dipanggil di dalam transaksi posting, setelah baris produk dikunci.
 */
final class WeightedCost
{
    /**
     * @return array{before: string|null, after: string}
     */
    public static function apply(Product $product, int $qty, string $unitCost): array
    {
        $before = $product->cost_price !== null ? Money::of($product->cost_price) : null;
        $onHand = (int) ProductStock::query()->withoutGlobalScopes()->where('tenant_id', $product->tenant_id)
            ->where('product_id', $product->id)->where('stock_qty', '>', 0)->sum('stock_qty');

        $after = $before === null || $onHand === 0
            ? Money::of($unitCost)
            : Money::round(bcdiv(Money::add(Money::mul($before, $onHand), Money::mul($unitCost, $qty)), (string) ($onHand + $qty), 6));

        $product->forceFill(['cost_price' => $after])->save();

        return ['before' => $before, 'after' => $after];
    }
}
