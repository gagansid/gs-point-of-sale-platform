<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
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
            'stock_qty' => 0,
            'is_active' => true,
            'is_available' => true,
            'is_favorite' => false,
            'min_stock' => null,
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
