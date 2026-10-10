<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Actions\Product\Data\OptionGroupData;
use App\Actions\Product\Data\ProductData;
use App\Actions\Product\SaveCategory;
use App\Actions\Product\SaveOptionGroup;
use App\Actions\Product\SaveProduct;
use App\Enums\BusinessType;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Admin;
use App\Models\Category;
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
                $model = User::query()->firstOrCreate(['name' => $user['name']], [
                    'password' => $user['email'] !== null ? 'password' : null,
                    'pin' => $user['pin'] ?? null,
                    'is_active' => true,
                    ...$user,
                ]);

                // Akun demo dianggap sudah verifikasi email (ADR 0009)
                if ($user['email'] !== null && ! $model->hasVerifiedEmail()) {
                    $model->forceFill(['email_verified_at' => now()])->save();
                }
            }

            Device::query()->firstOrCreate(['device_uid' => 'demo-device-01'], [
                'outlet_id' => $outlet->id,
                'name' => 'Kasir Depan',
            ]);

            app(CreateDefaultPaymentMethods::class)->handle();
            $this->seedCatalog();
        });
    }

    /** Katalog contoh kafe; dilewati bila sudah ada (aman dijalankan ulang). */
    private function seedCatalog(): void
    {
        if (Category::query()->exists()) {
            return;
        }

        $saveCategory = app(SaveCategory::class);
        $coffee = $saveCategory->handle(null, 'Kopi')['category'];
        $nonCoffee = $saveCategory->handle(null, 'Non Kopi')['category'];
        $food = $saveCategory->handle(null, 'Makanan')['category'];

        $saveGroup = app(SaveOptionGroup::class);
        $size = $saveGroup->handle(null, OptionGroupData::fromArray([
            'name' => 'Ukuran', 'min_select' => 1, 'max_select' => 1,
            'options' => [['name' => 'Regular', 'price_delta' => 0], ['name' => 'Large', 'price_delta' => 5000]],
        ]))['group'];
        $sugar = $saveGroup->handle(null, OptionGroupData::fromArray([
            'name' => 'Gula', 'min_select' => 0, 'max_select' => 1,
            'options' => [['name' => 'Normal'], ['name' => 'Less sugar'], ['name' => 'Tanpa gula']],
        ]))['group'];

        $saveProduct = app(SaveProduct::class);
        $products = [
            ['Es Kopi Susu', $coffee->id, 22000, [$size->id, $sugar->id], false, 0],
            ['Americano', $coffee->id, 20000, [$size->id], false, 0],
            ['Caffe Latte', $coffee->id, 26000, [$size->id, $sugar->id], false, 0],
            ['Matcha Latte', $nonCoffee->id, 28000, [$size->id, $sugar->id], false, 0],
            ['Teh Tarik', $nonCoffee->id, 18000, [$sugar->id], false, 0],
            ['Croissant', $food->id, 24000, [], true, 12],
            ['Banana Bread', $food->id, 21000, [], true, 0],
        ];

        foreach ($products as [$name, $categoryId, $price, $groups, $track, $stock]) {
            $saveProduct->handle(null, ProductData::fromArray([
                'name' => $name, 'category_id' => $categoryId, 'price' => $price,
                'cost_price' => (int) ($price * 0.4), 'track_stock' => $track, 'stock_qty' => $stock,
                'option_group_ids' => $groups,
            ]));
        }
    }
}
