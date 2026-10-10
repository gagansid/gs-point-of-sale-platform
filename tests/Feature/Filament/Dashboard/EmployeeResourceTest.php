<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Filament\Dashboard\Resources\Employees\EmployeeResource;
use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Models\Outlet;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->actingAs($this->owner);
    TenantContext::set($this->owner->tenant_id);
});

it('menambah kasir dengan PIN dari modal', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Budi', 'role' => UserRole::Cashier->value, 'pin' => '481920'])
        ->assertHasNoFormErrors();

    $budi = User::query()->where('name', 'Budi')->sole();
    expect($budi->role)->toBe(UserRole::Cashier)
        ->and($budi->email)->toBeNull()
        ->and(Hash::check('481920', (string) $budi->pin))->toBeTrue();
});

it('PIN lemah ditolak di form', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('create')->table(), ['name' => 'Budi', 'role' => UserRole::Cashier->value, 'pin' => '123456'])
        ->assertHasFormErrors(['pin']);
});

it('ubah nama tanpa mengisi ulang PIN', function () {
    $cashier = User::factory()->cashier()->forOutlet($this->outlet)->create(['pin' => '481920']);

    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('edit')->table($cashier), ['name' => 'Budi Baru'])
        ->assertHasNoFormErrors();

    expect($cashier->refresh()->name)->toBe('Budi Baru')
        ->and(Hash::check('481920', (string) $cashier->pin))->toBeTrue();
});

it('owner terakhir: nonaktifkan diri sendiri ditolak dengan notifikasi', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('deactivate')->table($this->owner))
        ->assertNotified('Tidak bisa menonaktifkan akun sendiri');

    expect($this->owner->refresh()->is_active)->toBeTrue();
});

it('manager tidak bisa membuka menu karyawan', function () {
    $this->actingAs(User::factory()->manager()->forOutlet($this->outlet)->create());

    expect(EmployeeResource::canViewAny())->toBeFalse();
    $this->get('/dashboard/settings/employees')->assertForbidden();
});

it('role manager menampilkan email & kata sandi; PIN opsional', function () {
    Livewire::test(ManageEmployees::class)
        ->callAction(TestAction::make('create')->table(), [
            'name' => 'Sari', 'role' => UserRole::Manager->value, 'email' => 'Sari@Kopi.test', 'password' => 'rahasia123',
        ])
        ->assertHasNoFormErrors();

    $sari = User::query()->where('name', 'Sari')->sole();
    expect($sari->role)->toBe(UserRole::Manager)
        ->and($sari->email)->toBe('sari@kopi.test')
        ->and($sari->pin)->toBeNull()
        ->and($sari->hasVerifiedEmail())->toBeTrue();
});
