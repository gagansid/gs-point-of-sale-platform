<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Menghapus kategori (soft delete). Produknya tetap ada dan menjadi "tanpa kategori".
 */
final class DeleteCategory
{
    public function handle(Category $category): void
    {
        DB::transaction(function () use ($category): void {
            Product::query()->where('category_id', $category->id)->update(['category_id' => null]);
            $category->delete();
        });
    }
}
