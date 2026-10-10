<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
final class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'outlet_id' => fn (array $a): string => Device::allTenants()->findOrFail($a['device_id'])->outlet_id,
            'tenant_id' => fn (array $a): string => Device::allTenants()->findOrFail($a['device_id'])->tenant_id,
            'opened_by' => fn (array $a): string => User::factory()->cashier()->forOutlet(Outlet::allTenants()->findOrFail($a['outlet_id']))->create()->id,
            'opening_cash' => '200000.00',
            'status' => ShiftStatus::Open,
            'open_device_key' => fn (array $a): string => $a['device_id'],
            'opened_at' => now(),
        ];
    }

    public function forDevice(Device $device, ?User $user = null): self
    {
        return $this->state(fn () => [
            'device_id' => $device->id,
            'outlet_id' => $device->outlet_id,
            'tenant_id' => $device->tenant_id,
            'opened_by' => $user->id ?? User::factory()->cashier()->forOutlet(Outlet::allTenants()->findOrFail($device->outlet_id))->create()->id,
            'open_device_key' => $device->id,
        ]);
    }

    public function closed(): self
    {
        return $this->state(['status' => ShiftStatus::Closed, 'open_device_key' => null, 'closed_at' => now()]);
    }
}
