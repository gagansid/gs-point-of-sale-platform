<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Data demo untuk development lokal SAJA. Ditolak di environment selain local/testing.
 *
 * Kredensial demo (lokal):
 *   Super admin  admin@demo.test       kata sandi: password
 *   Owner        owner@demo.test       kata sandi: password
 *   Manager      manager@demo.test     kata sandi: password
 *   Supervisor   Sari (PIN 222222)
 *   Kasir        Budi (PIN 123456)
 */
final class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('DemoTenantSeeder hanya untuk environment local/testing');
        }

        Admin::query()->firstOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Super Admin Demo', 'password' => 'password'],
        );

        $tenant = Tenant::query()->firstOrCreate(['slug' => 'kopi-senja-demo'], [
            'name' => 'Kopi Senja (Demo)',
            'business_type' => BusinessType::Cafe,
            'status' => TenantStatus::Active,
            'subscription_ends_at' => now()->addYear(),
        ]);

        TenantContext::run($tenant->id, function (): void {
            $outlet = Outlet::query()->firstOrCreate(['code' => 'DEMO01'], [
                'name' => 'Kopi Senja Kemang',
                'address' => 'Jl. Kemang Raya No. 1, Jakarta Selatan',
                'timezone' => 'Asia/Jakarta',
                'tax_rate' => '11.00',
                'service_charge_rate' => '5.00',
                'rounding' => 100,
                'receipt_footer' => 'Terima kasih, sampai jumpa lagi',
                'discount_limits' => config('pos.outlet_defaults.discount_limits'),
            ]);

            $users = [
                ['name' => 'Owner Demo', 'email' => 'owner@demo.test', 'role' => UserRole::Owner, 'outlet_id' => null],
                ['name' => 'Manager Demo', 'email' => 'manager@demo.test', 'role' => UserRole::Manager, 'outlet_id' => $outlet->id],
                ['name' => 'Sari', 'email' => null, 'role' => UserRole::Supervisor, 'outlet_id' => $outlet->id, 'pin' => '222222'],
                ['name' => 'Budi', 'email' => null, 'role' => UserRole::Cashier, 'outlet_id' => $outlet->id, 'pin' => '123456'],
            ];

            foreach ($users as $user) {
                User::query()->firstOrCreate(['name' => $user['name']], [
                    'password' => $user['email'] !== null ? 'password' : null,
                    'pin' => $user['pin'] ?? null,
                    'is_active' => true,
                    ...$user,
                ]);
            }

            Device::query()->firstOrCreate(['device_uid' => 'demo-device-01'], [
                'outlet_id' => $outlet->id,
                'name' => 'Kasir Depan',
            ]);
        });
    }
}
