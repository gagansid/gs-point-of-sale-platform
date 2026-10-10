<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Category;
use App\Support\Idempotency;
use Illuminate\Support\Facades\DB;

/**
 * Membuat (category = null) atau mengubah kategori. Membuat dengan id yang sudah ada → data lama.
 */
final class SaveCategory
{
    /**
     * @param  bool|null  $isActive  null = tidak berubah (kategori baru: aktif)
     * @return array{category: Category, replayed: bool}
     */
    public function handle(?Category $category, string $name, ?int $sortOrder = null, ?string $id = null, ?bool $isActive = null): array
    {
        if ($category === null && ($existing = Idempotency::existing(Category::class, $id)) !== null) {
            return ['category' => $existing, 'replayed' => true];
        }

        $category = DB::transaction(function () use ($category, $name, $sortOrder, $id, $isActive): Category {
            $category ??= new Category;

            if (! $category->exists && $id !== null) {
                $category->id = $id;
            }

            $category->fill([
                'name' => trim($name),
                // Kategori baru di urutan terakhir bila urutan tidak ditentukan
                'sort_order' => $sortOrder ?? ($category->exists ? $category->sort_order : (int) Category::query()->max('sort_order') + 1),
                'is_active' => $isActive ?? ($category->exists ? $category->is_active : true),
            ])->save();

            return $category;
        });

        return ['category' => $category, 'replayed' => false];
    }
}
