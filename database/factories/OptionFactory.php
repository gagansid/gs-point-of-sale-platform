<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Option;
use App\Models\OptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Option>
 */
final class OptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'option_group_id' => OptionGroup::factory(),
            'tenant_id' => fn (array $a): string => OptionGroup::allTenants()->findOrFail($a['option_group_id'])->tenant_id,
            'name' => fake()->randomElement(['Regular', 'Large', 'Less sugar', 'Extra shot']),
            'price_delta' => '0.00',
            'sort_order' => 0,
        ];
    }
}
