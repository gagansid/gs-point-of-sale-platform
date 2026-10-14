<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Enums\PaymentCategory;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\Outlet;
use App\Models\OutletOption;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Promotion;
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
     *     payment_methods: Collection<int, PaymentMethod>,
     *     promotions: Collection<int, Promotion>
     * }
     */
    public function handle(Outlet $outlet): array
    {
        // Bahan baku tidak dijual (ADR 0020)
        $products = Product::query()->atOutlet($outlet->id)->active()->sellable()->listedAt($outlet->id)
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
            // Online (Midtrans) dibayar pelanggan dari HP, bukan dipilih kasir (ADR 0014)
            'payment_methods' => PaymentMethod::query()->activeAt($outlet->id)->where('category', '!=', PaymentCategory::Online)->orderBy('sort_order')->get(),
            // Promo otomatis aktif untuk outlet ini beserta jadwalnya (ADR 0022); nominal final dari server
            'promotions' => Promotion::query()->where('is_active', true)->orderBy('name')->get()
                ->filter(fn (Promotion $promo): bool => $promo->outlet_ids === null || $promo->outlet_ids === [] || in_array($outlet->id, $promo->outlet_ids, true))
                ->filter(fn (Promotion $promo): bool => $promo->ends_on === null || $promo->ends_on->toDateString() >= $outlet->localDate())
                ->values(),
        ];
    }
}
