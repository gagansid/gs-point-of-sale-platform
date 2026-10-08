<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
final class OutletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'code' => fake()->unique()->bothify('???##'),
            'name' => 'Outlet '.fake()->city(),
            'address' => fake()->address(),
            'timezone' => config('pos.default_timezone'),
            'tax_rate' => '11.00',
            'service_charge_rate' => '0.00',
            'tax_inclusive' => false,
            'rounding' => 100,
            'receipt_header' => null,
            'receipt_footer' => 'Terima kasih',
            'discount_limits' => config('pos.outlet_defaults.discount_limits'),
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(fn (Outlet $outlet) => $outlet->code = strtoupper($outlet->code));
    }
}
