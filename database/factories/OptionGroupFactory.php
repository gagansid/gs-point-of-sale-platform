<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OptionGroup;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OptionGroup>
 */
final class OptionGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'name' => 'Ukuran '.fake()->unique()->numberBetween(1, 9999),
            'min_select' => 1,
            'max_select' => 1,
            'sort_order' => 0,
        ];
    }
}
