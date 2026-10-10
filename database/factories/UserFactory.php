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
 * Outlet (ADR 0010): non-owner default ditugaskan ke semua outlet tenant yang sudah ada;
 * forOutlet() = hanya outlet itu, withoutOutlets() = tanpa penugasan.
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    private static ?string $password = null;

    private static ?string $pin = null;

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->role->allows('outlet.access_all')) {
                return;
            }

            // Default semua outlet; forOutlet()/withoutOutlets() menimpa sesudahnya (callback berurutan)
            $user->outlets()->sync(Outlet::forTenant($user->tenant_id)->pluck('id')->all());
            $user->forgetOutletIds();
        });
    }

    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::id() ?? Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
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

    /** Owner hasil daftar mandiri yang belum klik link verifikasi (ADR 0009). */
    public function unverified(): self
    {
        return $this->state(['email_verified_at' => null]);
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

    public function forOutlet(Outlet ...$outlets): self
    {
        return $this->state(['tenant_id' => $outlets[0]->tenant_id])
            ->afterCreating(function (User $user) use ($outlets): void {
                $user->outlets()->sync(array_map(fn (Outlet $outlet): string => $outlet->id, $outlets));
                $user->forgetOutletIds();
            });
    }

    public function withoutOutlets(): self
    {
        return $this->afterCreating(function (User $user): void {
            $user->outlets()->detach();
            $user->forgetOutletIds();
        });
    }
}
