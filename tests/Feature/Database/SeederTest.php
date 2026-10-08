<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\AppVersion;
use App\Models\Device;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenantSeeder;

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
