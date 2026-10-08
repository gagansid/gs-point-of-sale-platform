<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\ProductData;
use App\Enums\StockMovementType;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Membuat (product = null) atau mengubah produk beserta urutan grup opsinya.
 * Stok awal hanya saat membuat; perubahan stok berikutnya lewat AdjustStock.
 */
final class SaveProduct
{
    /**
     * @return array{product: Product, replayed: bool}
     */
    public function handle(?Product $product, ProductData $data, ?User $by = null): array
    {
        if ($product === null && ($existing = Idempotency::existing(Product::class, $data->id)) !== null) {
            return ['product' => $existing, 'replayed' => true];
        }

        $product = DB::transaction(function () use ($product, $data, $by): Product {
            $isNew = $product === null;
            $product ??= new Product;

            if ($isNew && $data->id !== null) {
                $product->id = $data->id;
            }

            $product->fill([
                'category_id' => $data->categoryId,
                'name' => $data->name,
                'sku' => $data->sku,
                'barcode' => $data->barcode,
                'price' => $data->price,
                'cost_price' => $data->costPrice,
                'track_stock' => $data->trackStock,
                'image_path' => $data->imagePathProvided ? $data->imagePath : $product->image_path,
                'is_active' => $data->isActive,
            ]);

            if ($isNew) {
                $product->stock_qty = $data->trackStock ? $data->initialStock : 0;
            }

            $product->save();

            if ($isNew && $data->trackStock && $data->initialStock !== 0) {
                StockMovement::query()->create([
                    'product_id' => $product->id,
                    'user_id' => $by?->id,
                    'type' => StockMovementType::Adjustment,
                    'qty_change' => $data->initialStock,
                    'qty_after' => $data->initialStock,
                    'reason' => 'Stok awal',
                ]);
            }

            $this->syncOptionGroups($product, $data->optionGroupIds);

            return $product;
        });

        return ['product' => $product->refresh(), 'replayed' => false];
    }

    /**
     * @param  list<string>  $groupIds
     */
    private function syncOptionGroups(Product $product, array $groupIds): void
    {
        // Hanya grup milik tenant aktif yang bisa dihubungkan (validasi request juga memeriksa)
        $valid = OptionGroup::query()->whereIn('id', $groupIds)->pluck('id')->all();

        $sync = [];
        foreach ($groupIds as $order => $groupId) {
            if (in_array($groupId, $valid, true)) {
                $sync[$groupId] = ['sort_order' => $order];
            }
        }

        $product->optionGroups()->sync($sync);
    }
}
