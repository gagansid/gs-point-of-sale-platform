<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Actions\Product\Data\ProductData;
use App\Enums\ErrorCode;
use App\Enums\ProductKind;
use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock\StockConsumption;
use App\Support\Idempotency;
use App\Support\Quotas;
use App\Support\TenantContext;
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
        $kind = $data->kind ?? $product->kind ?? ProductKind::Regular;
        $components = $data->components ?? ($product !== null ? $product->components()->get(['component_id', 'qty'])->map(fn (ProductComponent $c): array => ['component_id' => $c->component_id, 'qty' => $c->qty])->all() : []);
        $this->validateComposition($product, $kind, $components);

        $product = DB::transaction(function () use ($product, $data, $outlet, $by, $kind, $components): Product {
            $isNew = $product === null;
            $product ??= new Product;
            $wasCounted = ! $isNew && Quotas::countsAsProduct($product);

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
                'kind' => $kind,
                'unit' => $data->unit ?? $product->unit ?? 'pcs',
                // ADR 0020: bahan selalu dilacak; paket & produk beresep memotong stok komponennya
                'track_stock' => self::tracksStock($kind, $components, $data->trackStock),
                'image_path' => $data->imagePathProvided ? $data->imagePath : $product->image_path,
                'is_active' => $data->isActive,
                'is_favorite' => $data->isFavorite,
            ]);

            // Kuota produk aktif (ADR 0025): produk baru, diaktifkan lagi, atau bahan baku dijadikan produk jualan
            if (! $wasCounted && Quotas::countsAsProduct($product)) {
                Quotas::ensureAvailable(TenantContext::idOrFail(), Quotas::PRODUCTS);
            }

            $product->save();

            $stock = ProductStock::for($product->id, $outlet->id, lock: true);
            $stock->min_stock = $data->minStock;

            if ($isNew) {
                $stock->stock_qty = $product->track_stock ? $data->initialStock : 0;
            }

            $stock->save();

            if ($isNew && $product->track_stock && $data->initialStock !== 0) {
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

            // Bahan baku tidak dijual: tanpa grup opsi
            $this->syncOptionGroups($product, $kind === ProductKind::Ingredient ? [] : $data->optionGroupIds);
            $this->syncComponents($product, $kind === ProductKind::Ingredient ? [] : $components);

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
     * @param  list<array{component_id: string, qty: int}>  $components
     */
    private static function tracksStock(ProductKind $kind, array $components, bool $requested): bool
    {
        return match ($kind) {
            ProductKind::Ingredient => true,
            ProductKind::Bundle => false,
            ProductKind::Regular => $components === [] && $requested,
        };
    }

    /**
     * Aturan resep (ADR 0020): bahan tanpa resep, paket wajib berisi, komponen milik tenant ini, bukan paket, bukan
     * dirinya sendiri, dan tidak membentuk siklus.
     *
     * @param  list<array{component_id: string, qty: int}>  $components
     *
     * @throws BusinessException
     */
    private function validateComposition(?Product $product, ProductKind $kind, array $components): void
    {
        $fail = fn (string $message) => throw BusinessException::of(ErrorCode::ValidationError, $message, ['components' => [$message]]);

        if ($kind === ProductKind::Bundle && $components === []) {
            $fail('Paket wajib berisi minimal satu produk.');
        }

        if ($kind === ProductKind::Ingredient || $components === []) {
            return;
        }

        $ids = array_column($components, 'component_id');
        $found = Product::query()->whereIn('id', $ids)->get(['id', 'kind', 'name'])->keyBy('id');

        foreach ($components as $row) {
            $component = $found->get($row['component_id']) ?? $fail('Komponen resep tidak ditemukan.');

            if ($product !== null && $component->id === $product->id) {
                $fail('Produk tidak bisa menjadi komponen dirinya sendiri.');
            }

            if ($component->kind === ProductKind::Bundle) {
                $fail("{$component->name} adalah paket dan tidak bisa menjadi komponen.");
            }

            if ($product !== null && in_array($product->id, app(StockConsumption::class)->descendants($component->id), true)) {
                $fail("{$component->name} sudah memakai produk ini di resepnya.");
            }

            if ($row['qty'] > 1_000_000) {
                $fail('Jumlah komponen maksimal 1.000.000.');
            }
        }

        // Produk yang sudah jadi komponen produk lain tidak boleh berubah menjadi paket
        if ($kind === ProductKind::Bundle && $product !== null && ProductComponent::query()->where('component_id', $product->id)->exists()) {
            $fail('Produk ini dipakai di resep/paket lain sehingga tidak bisa menjadi paket.');
        }
    }

    /**
     * @param  list<array{component_id: string, qty: int}>  $components
     */
    private function syncComponents(Product $product, array $components): void
    {
        ProductComponent::query()->where('product_id', $product->id)->delete();

        foreach ($components as $position => $row) {
            ProductComponent::query()->create([
                'product_id' => $product->id,
                'component_id' => $row['component_id'],
                'qty' => $row['qty'],
                'sort_order' => $position,
            ]);
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
