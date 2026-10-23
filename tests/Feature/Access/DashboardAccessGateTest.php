<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Dashboard\Pages\DashboardPage;
use App\Filament\Dashboard\Pages\Home;
use App\Filament\Dashboard\Pages\Reports;
use App\Filament\Dashboard\Resources\DashboardResource;
use App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\RolePermissions;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Str;

/**
 * Gerbang akses panel /dashboard terpusat (ADR 0026): semua menu lewat DashboardResource/DashboardPage, pengecualian
 * tanpa dashboard.access hanya Laporan (Q50) & Stok masuk (Q79).
 */

/** @return list<class-string> */
function dashboardClasses(string $dir, string $pattern): array
{
    return collect(glob(app_path("Filament/Dashboard/{$dir}/{$pattern}")) ?: [])
        ->map(fn (string $file): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file, app_path().'/')))
        ->filter(fn (string $class): bool => ! (new ReflectionClass($class))->isAbstract())
        ->values()->all();
}

/** @param  class-string  $class */
function requiresFullDashboard(string $class): bool
{
    return (new ReflectionClass($class))->getStaticPropertyValue('requiresFullDashboard');
}

it('setiap Resource dashboard turunan DashboardResource', function () {
    $resources = dashboardClasses('Resources', '*/*Resource.php');

    expect($resources)->not->toBeEmpty();
    foreach ($resources as $class) {
        expect(is_subclass_of($class, DashboardResource::class))->toBeTrue("{$class} harus extends DashboardResource");
    }
});

it('setiap Page dashboard turunan DashboardPage (kecuali Beranda yang memeriksa sendiri)', function () {
    foreach (dashboardClasses('Pages', '*.php') as $class) {
        if ($class === Home::class) {
            continue;
        }

        expect(is_subclass_of($class, DashboardPage::class))->toBeTrue("{$class} harus extends DashboardPage");
    }
});

it('pengecualian tanpa dashboard.access hanya Laporan & Stok masuk', function () {
    $exempt = collect([...dashboardClasses('Resources', '*/*Resource.php'), ...array_filter(dashboardClasses('Pages', '*.php'), fn (string $c): bool => $c !== Home::class)])
        ->reject(fn (string $class): bool => requiresFullDashboard($class))
        ->values()->all();

    expect($exempt)->toEqualCanonicalizing([Reports::class, StockReceiptResource::class]);
});

it('tanpa dashboard.access, semua permission lain pun hanya membuka Laporan & Stok masuk', function () {
    Filament::setCurrentPanel('dashboard');
    $pos = posSetup();
    TenantContext::set($pos->tenantId);

    // Supervisor diberi semua izin yang bisa didelegasikan kecuali dashboard.access
    RolePermission::query()->create(['role' => UserRole::Supervisor, 'permissions' => array_values(array_diff(
        array_filter(UserRole::PERMISSIONS, UserRole::isDelegable(...)), ['dashboard.access'],
    ))]);
    RolePermissions::flush();
    $supervisor = $pos->supervisor->forceFill(['email_verified_at' => now()]);
    $supervisor->save();
    test()->actingAs($supervisor);
    CurrentOutlet::set($supervisor);

    $open = collect([...dashboardClasses('Resources', '*/*Resource.php'), ...array_filter(dashboardClasses('Pages', '*.php'), fn (string $c): bool => $c !== Home::class)])
        ->filter(fn (string $class): bool => is_subclass_of($class, DashboardResource::class) ? $class::canViewAny() : $class::canAccess())
        ->values()->all();

    expect($open)->toEqualCanonicalizing([Reports::class, StockReceiptResource::class])
        ->and(User::query()->whereKey($supervisor->id)->sole()->hasFullDashboard())->toBeFalse();
});
