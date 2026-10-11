<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\ProductData;
use App\Enums\StockMovementType;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Membuat (product = null) atau mengubah produk beserta urutan grup opsinya.
 * Harga berlaku di semua outlet; stok awal dan stok minimum untuk $outlet (ADR 0010).
 * Outlet tempat produk dijual diatur lewat listedOutletIds (ADR 0011); null = tidak diubah, produk baru dijual di semua outlet.
 * Stok awal hanya saat membuat; perubahan stok berikutnya lewat AdjustStock.
 */
final class SaveProduct
{
    public function __construct(private readonly SetProductListing $listing) {}

    /**
     * @return array{product: Product, replayed: bool}
     */
    public function handle(?Product $product, ProductData $data, Outlet $outlet, ?User $by = null): array
    {
        if ($product === null && ($existing = Idempotency::existing(Product::class, $data->id)) !== null) {
            return ['product' => $existing->loadOutletState($outlet->id), 'replayed' => true];
        }

        $oldImage = $product?->image_path;

        $product = DB::transaction(function () use ($product, $data, $outlet, $by): Product {
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
                'is_favorite' => $data->isFavorite,
            ]);

            $product->save();

            $stock = ProductStock::for($product->id, $outlet->id, lock: true);
            $stock->min_stock = $data->minStock;

            if ($isNew) {
                $stock->stock_qty = $data->trackStock ? $data->initialStock : 0;
            }

            $stock->save();

            if ($isNew && $data->trackStock && $data->initialStock !== 0) {
                StockMovement::query()->create([
                    'outlet_id' => $outlet->id,
                    'product_id' => $product->id,
                    'user_id' => $by?->id,
                    'type' => StockMovementType::Adjustment,
                    'qty_change' => $data->initialStock,
                    'qty_after' => $data->initialStock,
                    'reason' => 'Stok awal',
                ]);
            }

            $this->syncOptionGroups($product, $data->optionGroupIds);

            if ($data->listedOutletIds !== null) {
                $this->listing->handle($product, $data->listedOutletIds, $by);
            }

            return $product;
        });

        // Gambar diganti/dihapus: file lama dibuang setelah data tersimpan (T2)
        if ($data->imagePathProvided && $oldImage !== null && $oldImage !== $product->image_path) {
            self::deleteImage($product, $oldImage);
        }

        return ['product' => $product->refresh()->loadOutletState($outlet->id), 'replayed' => false];
    }

    /**
     * Hapus file gambar lama di disk public. Hanya di folder produk tenant ini dan bila tidak dipakai
     * produk lain (mis. hasil salin), agar tidak pernah menghapus file sembarang path.
     */
    private static function deleteImage(Product $product, string $path): void
    {
        $inTenantFolder = str_starts_with($path, 'products/'.$product->tenant_id.'/') && ! str_contains($path, '..');

        if ($inTenantFolder && Product::withTrashed()->where('image_path', $path)->doesntExist()) {
            Storage::disk('public')->delete($path);
        }
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
