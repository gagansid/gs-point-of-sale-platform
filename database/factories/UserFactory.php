<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Kredensial default: kata sandi "password", PIN "123456" (hanya untuk test & data demo lokal).
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    private static ?string $password = null;

    private static ?string $pin = null;

    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'outlet_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Hash disimpan sekali agar test tidak lambat
            'password' => self::$password ??= Hash::make('password'),
            'pin' => self::$pin ??= Hash::make('123456'),
            'role' => UserRole::Cashier,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(UserRole $role): self
    {
        return $this->state(['role' => $role]);
    }

    public function owner(): self
    {
        return $this->role(UserRole::Owner);
    }

    public function manager(): self
    {
        return $this->role(UserRole::Manager);
    }

    public function supervisor(): self
    {
        return $this->role(UserRole::Supervisor);
    }

    public function cashier(): self
    {
        return $this->role(UserRole::Cashier);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function forOutlet(Outlet $outlet): self
    {
        return $this->state(['tenant_id' => $outlet->tenant_id, 'outlet_id' => $outlet->id]);
    }
}
