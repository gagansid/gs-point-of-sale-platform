<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\DeleteCategory;
use App\Actions\Product\SaveCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Product\CategoryRequest;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Katalog & produk')]
final class CategoryController extends Controller
{
    /**
     * Tambah kategori.
     *
     * Permission product.manage. `id` opsional (UUID dari app) sebagai idempotency key.
     */
    public function store(CategoryRequest $request, SaveCategory $action): JsonResponse
    {
        $result = $action->handle(
            null,
            $request->string('name')->toString(),
            $request->has('sort_order') ? $request->integer('sort_order') : null,
            $request->filled('id') ? $request->string('id')->toString() : null,
        );

        return ApiResponse::success(
            CategoryResource::make($result['category'])->resolve($request),
            'Kategori berhasil ditambahkan',
            $result['replayed'] ? 200 : 201,
            ['idempotent_replay' => $result['replayed']],
        );
    }

    /**
     * Ubah kategori.
     */
    public function update(CategoryRequest $request, Category $category, SaveCategory $action): JsonResponse
    {
        $result = $action->handle(
            $category,
            $request->string('name')->toString(),
            $request->has('sort_order') ? $request->integer('sort_order') : null,
        );

        return ApiResponse::success(CategoryResource::make($result['category'])->resolve($request), 'Kategori berhasil diperbarui');
    }

    /**
     * Hapus kategori.
     *
     * Produk di kategori ini tidak ikut terhapus; menjadi tanpa kategori.
     */
    public function destroy(Request $request, Category $category, DeleteCategory $action): JsonResponse
    {
        abort_unless($request->user()?->can('product.manage') ?? false, 403);

        $action->handle($category);

        return ApiResponse::success(null, 'Kategori berhasil dihapus');
    }
}
