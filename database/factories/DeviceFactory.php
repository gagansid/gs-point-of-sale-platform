<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppPlatform;
use App\Models\Device;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
final class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            // Tenant device selalu sama dengan tenant outlet-nya
            'tenant_id' => fn (array $attributes): string => Outlet::allTenants()->findOrFail($attributes['outlet_id'])->tenant_id,
            'name' => 'Kasir '.fake()->numberBetween(1, 9),
            'device_uid' => (string) Str::uuid(),
            'platform' => AppPlatform::Android,
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
        ];
    }

    public function forOutlet(Outlet $outlet): self
    {
        return $this->state(['tenant_id' => $outlet->tenant_id, 'outlet_id' => $outlet->id]);
    }

    public function revoked(): self
    {
        return $this->state(['revoked_at' => now()]);
    }
}
