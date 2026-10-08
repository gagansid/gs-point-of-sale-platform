<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Category;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'name' => fake()->randomElement(['Kopi', 'Non Kopi', 'Teh', 'Makanan', 'Snack']).' '.fake()->unique()->numberBetween(1, 9999),
            'sort_order' => 0,
        ];
    }
}
