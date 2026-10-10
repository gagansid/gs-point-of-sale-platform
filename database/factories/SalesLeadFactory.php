<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SalesLeadStatus;
use App\Models\SalesLead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesLead>
 */
final class SalesLeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'business_name' => 'Kopi '.fake()->lastName(),
            'phone' => '0812'.fake()->numerify('########'),
            'email' => null,
            'city' => fake()->city(),
            'business_type' => 'cafe',
            'message' => null,
            'status' => SalesLeadStatus::New,
        ];
    }
}
