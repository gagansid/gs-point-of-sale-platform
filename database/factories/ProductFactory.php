<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * stock_qty, is_available, dan min_stock boleh diisi seperti kolom produk (state/create); nilainya
 * disimpan ke ProductStock untuk setiap outlet tenant (ADR 0010).
 *
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    private const STOCK_FIELDS = ['stock_qty', 'is_available', 'min_stock'];

    /** @var \WeakMap<Product, array<string, mixed>>|null */
    private static ?\WeakMap $pendingStock = null;

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Product $product): void {
                $stock = array_intersect_key($product->getAttributes(), array_flip(self::STOCK_FIELDS));

                foreach (self::STOCK_FIELDS as $field) {
                    $product->offsetUnset($field);
                }

                self::$pendingStock ??= new \WeakMap;
                self::$pendingStock[$product] = $stock;
            })
            ->afterCreating(function (Product $product): void {
                $stock = self::$pendingStock[$product] ?? [];

                foreach (Outlet::forTenant($product->tenant_id)->pluck('id') as $outletId) {
                    $row = new ProductStock(['outlet_id' => $outletId, 'product_id' => $product->id]);
                    $row->forceFill(['tenant_id' => $product->tenant_id, ...$stock])->saveQuietly();
                }

                $outletId = Outlet::forTenant($product->tenant_id)->orderBy('created_at')->value('id');
                if (is_string($outletId)) {
                    $product->loadOutletState($outletId);
                }
            });
    }

    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'category_id' => null,
            'name' => fake()->randomElement(['Es Kopi Susu', 'Americano', 'Latte', 'Croissant', 'Matcha']).' '.fake()->unique()->numberBetween(1, 9999),
            'sku' => null,
            'barcode' => null,
            'price' => '25000.00',
            'cost_price' => '9000.00',
            'track_stock' => false,
            'is_active' => true,
            'is_favorite' => false,
            'sort_order' => 0,
        ];
    }

    public function forCategory(Category $category): self
    {
        return $this->state(['tenant_id' => $category->tenant_id, 'category_id' => $category->id]);
    }

    public function tracked(int $stock = 10): self
    {
        return $this->state(['track_stock' => true, 'stock_qty' => $stock]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
