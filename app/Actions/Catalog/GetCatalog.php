<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Seluruh katalog tenant untuk aplikasi kasir dalam satu muatan (GET /catalog).
 * Produk nonaktif tidak dikirim; produk "habis" tetap dikirim dengan is_available=false.
 */
final class GetCatalog
{
    /**
     * @return array{
     *     categories: Collection<int, Category>,
     *     products: Collection<int, Product>,
     *     option_groups: Collection<int, OptionGroup>,
     *     payment_methods: Collection<int, PaymentMethod>
     * }
     */
    public function handle(): array
    {
        return [
            // Kategori/grup opsi nonaktif tidak dikirim; produk di kategori nonaktif ikut disembunyikan (Q27)
            'categories' => Category::query()->active()->orderBy('sort_order')->orderBy('name')->get(),
            'products' => Product::query()->active()
                ->where(fn (Builder $query) => $query->whereNull('category_id')
                    ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)))
                ->with(['optionGroups' => fn ($query) => $query->select('option_groups.id')->where('option_groups.is_active', true)])
                ->orderBy('sort_order')->orderBy('name')
                ->get(),
            'option_groups' => OptionGroup::query()->active()->with('options')->orderBy('sort_order')->orderBy('name')->get(),
            'payment_methods' => PaymentMethod::query()->active()->orderBy('sort_order')->get(),
        ];
    }
}
