<?php

declare(strict_types=1);

use App\Filament\Dashboard\Pages\OutletSettings;
use App\Models\Outlet;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outlet = Outlet::factory()->create(['code' => 'JKT01', 'name' => 'Kopi Senja', 'tax_rate' => '0.00']);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->outlet->tenant_id]);
    $this->actingAs($this->owner);
    TenantContext::set($this->owner->tenant_id);
});

it('owner menyimpan pajak, service, pembulatan, struk, dan batas diskon', function () {
    Livewire::test(OutletSettings::class)
        ->assertSet('data.code', 'JKT01')
        ->set('data.tax_rate', '11')
        ->set('data.service_charge_rate', '5')
        ->set('data.rounding', 500)
        ->set('data.receipt_footer', 'Sampai jumpa')
        ->set('data.discount_limits.cashier', 5)
        ->call('save')
        ->assertHasNoFormErrors();

    $outlet = $this->outlet->refresh();
    expect($outlet->tax_rate)->toBe('11.00')
        ->and($outlet->rounding)->toBe(500)
        ->and($outlet->receipt_footer)->toBe('Sampai jumpa')
        ->and($outlet->discount_limits['cashier'])->toEqual(5)
        ->and($outlet->code)->toBe('JKT01');
});

it('validasi form', function () {
    Livewire::test(OutletSettings::class)
        ->set('data.tax_rate', '150')
        ->set('data.name', '')
        ->call('save')
        ->assertHasFormErrors(['tax_rate', 'name']);
});

it('manager tidak melihat menu Profil outlet', function () {
    $this->actingAs(User::factory()->manager()->create(['tenant_id' => $this->outlet->tenant_id]));

    expect(OutletSettings::canAccess())->toBeFalse();
    $this->get('/dashboard/pengaturan/outlet')->assertForbidden();
});

it('tenant hanya-baca: halaman tampil tanpa tombol simpan', function () {
    Tenant::query()->whereKey($this->owner->tenant_id)->update(['subscription_ends_at' => now()->subDay()]);
    $this->owner->refresh();

    $this->get('/dashboard/pengaturan/outlet')->assertOk()->assertSee('Kopi Senja')->assertDontSee('Simpan</span>', escape: false);

    Livewire::test(OutletSettings::class)->call('save')->assertForbidden();
});
