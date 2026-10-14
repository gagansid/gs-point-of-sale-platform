<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\ProductKind;
use App\Models\OptionComponent;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Support\Money;

/**
 * Konsumsi stok & HPP per item (ADR 0020): produk dengan resep / paket memakai komponennya, opsi berbahan menambah
 * bahannya; tanpa resep = produk itu sendiri. Hanya komponen yang melacak stok yang dipotong.
 */
final class StockConsumption
{
    /**
     * Stok yang dipakai untuk $qty unit: [product_id => qty], hanya produk yang melacak stok.
     *
     * @param  list<string>  $optionIds
     * @return array<string, int>
     */
    public function for(string $productId, array $optionIds, int $qty): array
    {
        $usage = [];
        foreach ($this->perUnit($productId, $optionIds) as $id => $perUnit) {
            $usage[$id] = $perUnit * $qty;
        }

        $tracked = Product::query()->withTrashed()->whereIn('id', array_keys($usage))->where('track_stock', true)->pluck('id')->all();

        return array_intersect_key($usage, array_flip($tracked));
    }

    /**
     * HPP satu unit: Σ modal komponen × qty (+ opsi berbahan); tanpa resep = cost_price produk. Null bila tidak
     * ada modal sama sekali (sama dengan cost_price kosong sebelum ADR 0020).
     *
     * @param  list<string>  $optionIds
     */
    public function unitCost(Product $product, array $optionIds): ?string
    {
        $perUnit = $this->perUnit($product->id, $optionIds);

        if ($perUnit === [$product->id => 1]) {
            return $product->cost_price;
        }

        $costs = Product::query()->withTrashed()->whereIn('id', array_keys($perUnit))->pluck('cost_price', 'id');
        if ($costs->filter()->isEmpty()) {
            return null;
        }

        $total = '0.00';
        foreach ($perUnit as $id => $qty) {
            $total = Money::add($total, Money::mul(Money::fromDb($costs[$id] ?? null), $qty));
        }

        return $total;
    }

    /** Kedalaman resep maksimum (paket → produk beresep → bahan); penjaga siklus. */
    public const MAX_DEPTH = 3;

    /**
     * Komponen per 1 unit (belum disaring lacak stok). Komponen yang punya resep sendiri dipecah lagi sampai bahan.
     *
     * @param  list<string>  $optionIds
     * @return array<string, int>
     */
    private function perUnit(string $productId, array $optionIds): array
    {
        $usage = $this->expand($productId, 1, 0);

        if ($optionIds !== []) {
            foreach (OptionComponent::query()->whereIn('option_id', $optionIds)->get(['component_id', 'qty']) as $row) {
                foreach ($this->expand($row->component_id, $row->qty, 1) as $id => $qty) {
                    $usage[$id] = ($usage[$id] ?? 0) + $qty;
                }
            }
        }

        return $usage;
    }

    /** @return array<string, int> */
    private function expand(string $productId, int $qty, int $depth): array
    {
        $components = $depth < self::MAX_DEPTH ? ProductComponent::query()->where('product_id', $productId)->pluck('qty', 'component_id')->all() : [];

        if ($components === []) {
            // Paket tanpa isi tidak memotong apa pun; produk tanpa resep = dirinya sendiri
            $kind = Product::query()->withTrashed()->whereKey($productId)->value('kind');

            return $kind === ProductKind::Bundle->value ? [] : [$productId => $qty];
        }

        $usage = [];
        foreach ($components as $componentId => $perUnit) {
            foreach ($this->expand((string) $componentId, $qty * (int) $perUnit, $depth + 1) as $id => $amount) {
                $usage[$id] = ($usage[$id] ?? 0) + $amount;
            }
        }

        return $usage;
    }

    /**
     * Semua produk di bawah $productId pada resepnya (untuk cegah siklus saat menyimpan).
     *
     * @return list<string>
     */
    public function descendants(string $productId, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $ids = [];
        foreach (ProductComponent::query()->where('product_id', $productId)->pluck('component_id') as $componentId) {
            $ids[] = (string) $componentId;
            array_push($ids, ...$this->descendants((string) $componentId, $depth + 1));
        }

        return array_values(array_unique($ids));
    }
}
