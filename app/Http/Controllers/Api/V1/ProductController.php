<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\AdjustStock;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\DeleteProduct;
use App\Actions\Product\SaveProduct;
use App\Actions\Product\SetProductAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\AdjustStockRequest;
use App\Http\Requests\Api\V1\Product\AvailabilityRequest;
use App\Http\Requests\Api\V1\Product\ProductIndexRequest;
use App\Http\Requests\Api\V1\Product\ProductRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\StockMovementResource;
use App\Models\Product;
use App\Support\ApiActor;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Katalog & produk')]
final class ProductController extends Controller
{
    /**
     * Cari produk.
     *
     * Filter: search (nama/SKU/barcode), category_id, is_active. Pagination page & per_page (maks. 100).
     */
    public function index(ProductIndexRequest $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();

        $products = Product::query()
            ->with('optionGroups:id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                // LIKE dengan binding; karakter wildcard dari input di-escape
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn (Builder $q) => $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like));
            })
            ->when($request->filled('category_id'), fn (Builder $q) => $q->where('category_id', $request->string('category_id')->toString()))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(min($request->integer('per_page', (int) config('pos.pagination.per_page')), (int) config('pos.pagination.max_per_page')));

        return ApiResponse::paginated($products, ProductResource::class);
    }

    /**
     * Cari produk via barcode.
     *
     * Hanya produk aktif. 404 bila tidak ditemukan.
     */
    public function barcode(Request $request, string $code): JsonResponse
    {
        $product = Product::query()->active()->with('optionGroups:id')->where('barcode', $code)->firstOrFail();

        return ApiResponse::success(ProductResource::make($product)->resolve($request));
    }

    /**
     * Tambah produk.
     *
     * Permission product.manage. `stock_qty` = stok awal (hanya bila track_stock). `id` opsional sebagai idempotency key.
     */
    public function store(ProductRequest $request, SaveProduct $action): JsonResponse
    {
        $result = $action->handle(null, ProductData::fromArray($request->validated()), ApiActor::user($request));

        return ApiResponse::success(
            ProductResource::make($result['product']->load('optionGroups:id'))->resolve($request),
            'Produk berhasil ditambahkan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Ubah produk.
     *
     * Stok tidak bisa diubah di sini; gunakan penyesuaian stok.
     */
    public function update(ProductRequest $request, Product $product, SaveProduct $action): JsonResponse
    {
        $data = ProductData::fromArray([
            // Field yang tidak dikirim tetap memakai nilai lama
            'is_active' => $product->is_active,
            'track_stock' => $product->track_stock,
            'option_group_ids' => $product->optionGroups()->pluck('option_groups.id')->all(),
            ...$request->safe()->except('id'),
        ]);

        $result = $action->handle($product, $data, ApiActor::user($request));

        return ApiResponse::success(
            ProductResource::make($result['product']->load('optionGroups:id'))->resolve($request),
            'Produk berhasil diperbarui',
        );
    }

    /**
     * Hapus produk.
     */
    public function destroy(Request $request, Product $product, DeleteProduct $action): JsonResponse
    {
        abort_unless($request->user()?->can('product.manage') ?? false, 403);

        $action->handle($product);

        return ApiResponse::success(null, 'Produk berhasil dihapus');
    }

    /**
     * Penyesuaian stok.
     *
     * Permission stock.adjust. qty_change positif = tambah, negatif = kurang. `id` WAJIB (UUID dari app)
     * sebagai idempotency key: request ganda tidak mengubah stok dua kali. Hanya produk dengan track_stock.
     */
    public function adjustStock(AdjustStockRequest $request, Product $product, AdjustStock $action): JsonResponse
    {
        $result = $action->handle(
            $product,
            ApiActor::user($request),
            $request->string('id')->toString(),
            $request->integer('qty_change'),
            $request->string('reason')->toString(),
        );

        return ApiResponse::success([
            'movement' => StockMovementResource::make($result['movement'])->resolve($request),
            'product' => ProductResource::make($result['product'])->resolve($request),
        ], 'Stok berhasil disesuaikan', $result['replayed'] ? 200 : 201, ['idempotent_replay' => $result['replayed']]);
    }

    /**
     * Tandai menu habis / tersedia.
     *
     * Permission product.toggle_available (owner, manager, supervisor). Tidak mengubah data produk lain.
     */
    public function availability(AvailabilityRequest $request, Product $product, SetProductAvailability $action): JsonResponse
    {
        $product = $action->handle($product, $request->boolean('is_available'));

        return ApiResponse::success(
            ProductResource::make($product)->resolve($request),
            $product->is_available ? 'Menu ditandai tersedia' : 'Menu ditandai habis',
        );
    }
}
