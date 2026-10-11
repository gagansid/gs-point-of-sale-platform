<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\AppVersion;
use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoProductImagesSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\DemoTransactionsSeeder;
use Illuminate\Support\Facades\Storage;

it('membuat data demo lengkap dan aman dijalankan ulang', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Tenant::query()->count())->toBe(1)
        ->and(Outlet::allTenants()->count())->toBe(1)
        ->and(User::allTenants()->count())->toBe(4)
        ->and(Device::allTenants()->count())->toBe(1)
        ->and(Admin::query()->count())->toBe(1)
        ->and(AppVersion::query()->count())->toBe(1);
});

it('semua data demo berada di tenant demo', function () {
    $this->seed(DemoTenantSeeder::class);
    $tenantId = Tenant::query()->sole()->id;

    expect(User::allTenants()->pluck('tenant_id')->unique()->all())->toBe([$tenantId]);
});

it('menolak berjalan di production', function () {
    app()->detectEnvironment(fn () => 'production');

    // Dipanggil langsung: db:seed di production meminta konfirmasi interaktif lebih dulu
    app(DemoTenantSeeder::class)->run();
})->throws(RuntimeException::class, 'hanya untuk environment local/testing');

it('data demo lengkap: stok tidak minus setelah transaksi 7 hari, semua produk bergambar & ber-harga modal', function () {
    Storage::fake('public');
    $this->seed([DemoTenantSeeder::class, DemoProductImagesSeeder::class, DemoTransactionsSeeder::class]);

    $tenant = Tenant::query()->where('slug', 'kopi-senja-demo')->sole();
    TenantContext::run($tenant->id, function (): void {
        expect(ProductStock::query()->where('stock_qty', '<', 0)->count())->toBe(0)
            ->and(Product::query()->whereNull('image_path')->count())->toBe(0)
            ->and(Product::query()->whereNull('cost_price')->count())->toBe(0)
            ->and(Order::query()->count())->toBeGreaterThan(20);
    });
});
