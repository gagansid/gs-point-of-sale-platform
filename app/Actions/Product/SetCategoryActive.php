<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Aktifkan/nonaktifkan. Yang nonaktif tidak dikirim ke aplikasi kasir dan ditolak saat checkout (SPEC Q27).
 */
final class SetCategoryActive
{
    public function handle(Category $category, bool $active): Category
    {
        DB::transaction(fn () => $category->forceFill(['is_active' => $active])->save());

        return $category;
    }
}
