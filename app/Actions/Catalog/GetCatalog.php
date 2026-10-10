<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\OutletOption;
use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Seluruh katalog tenant untuk aplikasi kasir dalam satu muatan (GET /catalog).
 * Produk nonaktif tidak dikirim; produk "habis" tetap dikirim dengan is_available=false.
 * Harga sama di semua outlet; stok & ketersediaan dari outlet device (ADR 0010).
 * Menu per outlet (ADR 0011): hanya produk yang dijual di outlet, kategori yang punya produk itu,
 * opsi dengan is_available outlet, dan metode bayar yang aktif di outlet.
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
    public function handle(Outlet $outlet): array
    {
        $products = Product::query()->atOutlet($outlet->id)->active()->listedAt($outlet->id)
            // Produk di kategori nonaktif ikut disembunyikan (Q27)
            ->where(fn (Builder $query) => $query->whereNull('category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)))
            ->with(['optionGroups' => fn ($query) => $query->select('option_groups.id')->where('option_groups.is_active', true)])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $optionGroups = OptionGroup::query()->active()->with('options')->orderBy('sort_order')->orderBy('name')->get();
        $unavailable = OutletOption::unavailableIds($outlet->id);

        foreach ($optionGroups as $group) {
            foreach ($group->options as $option) {
                $option->setAttribute('is_available', ! in_array($option->id, $unavailable, true));
            }
        }

        return [
            // Kategori/grup opsi nonaktif tidak dikirim (Q27); kategori tanpa produk yang dijual di outlet juga tidak (Q42)
            'categories' => Category::query()->active()
                ->whereIn('id', $products->pluck('category_id')->filter()->unique()->values())
                ->orderBy('sort_order')->orderBy('name')->get(),
            'products' => $products,
            'option_groups' => $optionGroups,
            'payment_methods' => PaymentMethod::query()->activeAt($outlet->id)->orderBy('sort_order')->get(),
        ];
    }
}
