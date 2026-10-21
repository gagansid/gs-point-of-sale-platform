<?php

declare(strict_types=1);

use App\Actions\Admin\SetMaintenanceMode;
use App\Filament\Admin\Pages\MaintenanceMode;
use App\Models\Admin;
use Filament\Facades\Filament;
use Illuminate\Foundation\MaintenanceModeManager;
use Livewire\Livewire;

/**
 * Sistem → Maintenance. Driver "cache" + store array agar test tidak menulis storage/framework/down
 * (yang akan mematikan aplikasi lokal bila test berhenti di tengah).
 */
beforeEach(function () {
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    app(MaintenanceModeManager::class)->forgetDrivers();
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(['password' => 'Rahasia-Admin-1!']), 'admin');
});

afterEach(fn () => app(SetMaintenanceMode::class)->disable());

it('aktifkan dengan konfirmasi kata sandi → diarahkan ke URL secret; API 503 MAINTENANCE', function () {
    Livewire::test(MaintenanceMode::class)
        ->callAction('toggle', ['current_password' => 'salah'])
        ->assertHasActionErrors(['current_password']);
    expect(app(SetMaintenanceMode::class)->isEnabled())->toBeFalse();

    $page = Livewire::test(MaintenanceMode::class)->callAction('toggle', ['current_password' => 'Rahasia-Admin-1!']);
    $secret = app(SetMaintenanceMode::class)->secret();

    expect(app(SetMaintenanceMode::class)->isEnabled())->toBeTrue()->and($secret)->toHaveLength(40);
    $page->assertRedirect(url('/'.$secret));

    freshAuth();
    $this->getJson('/api/v1/system/status')->assertStatus(503)->assertJsonPath('error.code', 'MAINTENANCE');
});

it('matikan lagi', function () {
    app(SetMaintenanceMode::class)->enable();

    Livewire::test(MaintenanceMode::class)->assertSee('Pemeliharaan aktif')
        ->callAction('toggle', ['current_password' => 'Rahasia-Admin-1!']);

    expect(app(SetMaintenanceMode::class)->isEnabled())->toBeFalse();
});
