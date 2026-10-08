<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'business_type' => BusinessType::Cafe,
            'status' => TenantStatus::Active,
            'subscription_ends_at' => now()->addYear(),
        ];
    }

    public function trial(): self
    {
        return $this->state(['status' => TenantStatus::Trial, 'subscription_ends_at' => now()->addDays(14)]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => TenantStatus::Suspended]);
    }

    public function expired(): self
    {
        return $this->state(['subscription_ends_at' => now()->subDay()]);
    }
}
