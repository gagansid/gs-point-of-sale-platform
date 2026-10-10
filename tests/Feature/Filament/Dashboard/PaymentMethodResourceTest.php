<?php

declare(strict_types=1);

use App\Actions\Payment\CreateDefaultPaymentMethods;
use App\Filament\Dashboard\Resources\PaymentMethods\Pages\ManagePaymentMethods;
use App\Filament\Dashboard\Resources\PaymentMethods\PaymentMethodResource;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::factory()->create();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $outlet->tenant_id]);
    $this->actingAs($this->owner);
    TenantContext::set($this->owner->tenant_id);
    app(CreateDefaultPaymentMethods::class)->handle();
    $this->methods = PaymentMethod::query()->get()->keyBy(fn (PaymentMethod $m) => $m->category->value);
});

it('mengubah nama & wajib referensi dari modal', function () {
    Livewire::test(ManagePaymentMethods::class)
        ->assertCanSeeTableRecords($this->methods)
        ->callAction(TestAction::make('edit')->table($this->methods['qris']), ['name' => 'QRIS BCA', 'requires_reference' => true])
        ->assertHasNoFormErrors();

    expect($this->methods['qris']->refresh()->name)->toBe('QRIS BCA')
        ->and($this->methods['qris']->requires_reference)->toBeTrue();
});

it('menonaktifkan QRIS; tunai ditolak dengan notifikasi', function () {
    Livewire::test(ManagePaymentMethods::class)->callAction(TestAction::make('deactivate')->table($this->methods['qris']));
    expect($this->methods['qris']->refresh()->is_active)->toBeFalse();

    Livewire::test(ManagePaymentMethods::class)
        ->callAction(TestAction::make('deactivate')->table($this->methods['cash']))
        ->assertNotified('Tunai tidak bisa dinonaktifkan');
    expect($this->methods['cash']->refresh()->is_active)->toBeTrue();
});

it('tidak ada tambah/hapus; manager tidak bisa membuka menu', function () {
    expect(PaymentMethodResource::canCreate())->toBeFalse()
        ->and(PaymentMethodResource::canDelete($this->methods['qris']))->toBeFalse();

    $this->actingAs(User::factory()->manager()->create(['tenant_id' => $this->owner->tenant_id]));
    expect(PaymentMethodResource::canViewAny())->toBeFalse();
});
