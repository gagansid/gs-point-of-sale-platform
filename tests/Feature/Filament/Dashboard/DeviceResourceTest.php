<?php

declare(strict_types=1);

use App\Actions\Device\SetDeviceRevoked;
use App\Enums\AppPlatform;
use App\Filament\Dashboard\Resources\Devices\DeviceResource;
use App\Filament\Dashboard\Resources\Devices\Pages\ManageDevices;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Pengaturan → Perangkat: daftar, ganti nama, cabut, aktifkan lagi (SPEC Q54). */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
    $this->web = Device::factory()->forOutlet($this->pos->outlet)->create(['name' => 'Kasir web ab12', 'platform' => AppPlatform::Web, 'device_uid' => 'web:x:y']);
});

it('owner melihat tablet & kasir web; manager/kasir tidak punya menu', function () {
    Livewire::test(ManageDevices::class)->assertCanSeeTableRecords([$this->pos->device, $this->web])->assertSee('Kasir web');

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create());
    expect(DeviceResource::canViewAny())->toBeFalse();
    $this->get(DeviceResource::getUrl())->assertForbidden();
});

it('isolasi tenant: perangkat tenant lain tidak tampil', function () {
    $foreign = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);

    Livewire::test(ManageDevices::class)->assertCanNotSeeTableRecords([$foreign->device]);
});

it('ganti nama', function () {
    Livewire::test(ManageDevices::class)
        ->callAction(TestAction::make('edit')->table($this->web), ['name' => 'Laptop bar'])
        ->assertHasNoFormErrors();

    expect($this->web->fresh()?->name)->toBe('Laptop bar');
});

it('cabut: token perangkat & token user terikat dihapus; aktifkan lagi', function () {
    deviceToken($this->pos->device);
    userToken($this->pos->cashier, $this->pos->device);

    Livewire::test(ManageDevices::class)->callAction(TestAction::make('revoke')->table($this->pos->device));

    expect($this->pos->device->fresh()?->isRevoked())->toBeTrue()
        ->and(PersonalAccessToken::query()->where('device_id', $this->pos->device->id)->count())->toBe(0)
        ->and($this->pos->device->tokens()->count())->toBe(0);

    Livewire::test(ManageDevices::class)->callAction(TestAction::make('restore')->table($this->pos->device));
    expect($this->pos->device->fresh()?->isRevoked())->toBeFalse();
});

it('perangkat dengan shift terbuka tidak bisa dicabut', function () {
    Shift::factory()->forDevice($this->web, $this->pos->cashier)->create();

    Livewire::test(ManageDevices::class)
        ->assertSee('Terbuka · Budi')
        ->callAction(TestAction::make('revoke')->table($this->web))
        ->assertNotified('Perangkat ini masih punya shift terbuka. Tutup shift dulu (bisa tutup paksa dari aplikasi/kasir web).');

    expect($this->web->fresh()?->isRevoked())->toBeFalse();
});

it('kasir web dari browser yang dicabut diarahkan ke pilih outlet', function () {
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::run($this->pos->tenantId, fn () => app(SetDeviceRevoked::class)->handle($device, true));
    TenantContext::forget();

    $this->get(route('cashier.home'))->assertRedirect(route('cashier.outlet'));
});
