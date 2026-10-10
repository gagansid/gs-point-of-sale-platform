<?php

declare(strict_types=1);

use App\Actions\Outlet\CreateOutlet;
use App\Actions\Outlet\SetOutletActive;
use App\Enums\ErrorCode;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\OutletSettings;
use App\Filament\Dashboard\Resources\Outlets\OutletResource;
use App\Filament\Dashboard\Resources\Outlets\Pages\ManageOutlets;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Multi-outlet di dashboard (ADR 0010): menu Pengaturan → Outlet, batas paket, pemilih outlet topbar.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->outletA = Outlet::factory()->create(['code' => 'JKT01', 'name' => 'Jakarta', 'tax_rate' => '11.00']);
    $this->tenant = Tenant::query()->findOrFail($this->outletA->tenant_id);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->tenant->id]);
    $this->actingAs($this->owner);
    TenantContext::set($this->tenant->id);
    CurrentOutlet::set($this->owner);
});

describe('Pengaturan → Outlet', function () {
    it('owner menambah outlet; setelan pajak disalin dari outlet pertama', function () {
        Livewire::test(ManageOutlets::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Bandung', 'code' => 'bdg01', 'timezone' => 'Asia/Jakarta'])
            ->assertHasNoFormErrors();

        $outlet = Outlet::query()->where('code', 'BDG01')->sole();
        expect($outlet->tax_rate)->toBe('11.00')->and($outlet->is_active)->toBeTrue()
            ->and($this->owner->fresh()?->outletIds())->toContain($outlet->id);
    });

    it('kode outlet unik dalam satu bisnis', function () {
        expect(fn () => app(CreateOutlet::class)->handle('jkt01', 'Lagi', null, 'Asia/Jakarta'))
            ->toThrow(BusinessException::class);
        expect(Outlet::query()->count())->toBe(1);
    });

    it('batas paket: outlet aktif tidak boleh melebihi max_outlets', function () {
        $this->tenant->update(['max_outlets' => 1]);

        try {
            app(CreateOutlet::class)->handle('BDG01', 'Bandung', null, 'Asia/Jakarta');
            $this->fail('Seharusnya ditolak');
        } catch (BusinessException $e) {
            expect($e->errorCode)->toBe(ErrorCode::OutletLimitReached->value);
        }

        // Menonaktifkan satu outlet membuka slot; mengaktifkannya lagi kembali ditolak
        $this->tenant->update(['max_outlets' => 2]);
        $b = app(CreateOutlet::class)->handle('BDG01', 'Bandung', null, 'Asia/Jakarta');
        app(SetOutletActive::class)->handle($b, false);
        app(CreateOutlet::class)->handle('SBY01', 'Surabaya', null, 'Asia/Jakarta');

        expect(fn () => app(SetOutletActive::class)->handle($b, true))->toThrow(BusinessException::class)
            ->and(Outlet::query()->active()->count())->toBe(2);
    });

    it('outlet aktif terakhir tidak bisa dinonaktifkan', function () {
        expect(fn () => app(SetOutletActive::class)->handle($this->outletA, false))->toThrow(BusinessException::class)
            ->and($this->outletA->refresh()->is_active)->toBeTrue();
    });

    it('manager tidak bisa membuka menu Outlet', function () {
        $manager = User::factory()->manager()->forOutlet($this->outletA)->create();
        $this->actingAs($manager);

        expect(OutletResource::canViewAny())->toBeFalse()
            ->and(OutletResource::canCreate())->toBeFalse();
        $this->get(OutletResource::getUrl('index'))->assertForbidden();
    });

    it('bisnis hanya-baca tidak bisa menambah outlet', function () {
        $this->tenant->update(['subscription_ends_at' => now()->subDay()]);
        $this->owner->refresh();
        TenantContext::set($this->tenant->id, readOnly: true);

        expect(OutletResource::canCreate())->toBeFalse()
            ->and(fn () => app(CreateOutlet::class)->handle('BDG01', 'Bandung', null, 'Asia/Jakarta'))->toThrow(BusinessException::class);
    });

    it('profil outlet lain bisa diubah owner; outlet bisnis lain → 404', function () {
        $b = app(CreateOutlet::class)->handle('BDG01', 'Bandung', null, 'Asia/Jakarta');

        Livewire::withQueryParams(['outlet' => $b->id])->test(OutletSettings::class)
            ->assertSet('data.name', 'Bandung')
            ->set('data.name', 'Bandung Dago')
            ->call('save')
            ->assertHasNoErrors();
        expect($b->refresh()->name)->toBe('Bandung Dago')->and($this->outletA->refresh()->name)->toBe('Jakarta');

        $other = Tenant::factory()->create();
        $foreign = TenantContext::run($other->id, fn () => Outlet::factory()->create());
        $this->get(OutletSettings::getUrl(['outlet' => $foreign->id]))->assertNotFound();
    });
});

describe('pemilih outlet topbar', function () {
    beforeEach(function () {
        $this->outletB = app(CreateOutlet::class)->handle('BDG01', 'Bandung', null, 'Asia/Jakarta');
    });

    it('owner memilih outlet; pilihan tersimpan di session', function () {
        $this->post(route('filament.dashboard.switch-outlet', ['outlet' => $this->outletB->id]))->assertRedirect();
        expect(session('dashboard_outlet_id'))->toBe($this->outletB->id);

        $this->post(route('filament.dashboard.switch-outlet', ['outlet' => 'all']))->assertRedirect();
        expect(session('dashboard_outlet_id'))->toBeNull();
    });

    it('outlet yang tidak dipegang → 404', function () {
        $manager = User::factory()->manager()->forOutlet($this->outletA)->create();

        $this->actingAs($manager)
            ->post(route('filament.dashboard.switch-outlet', ['outlet' => $this->outletB->id]))
            ->assertNotFound();
        expect(session('dashboard_outlet_id'))->toBeNull();
    });

    it('daftar produk menampilkan stok outlet yang dipilih', function () {
        $product = Product::factory()->tracked(10)->create();
        stockOf($product, $this->outletB->id)->forceFill(['stock_qty' => 0])->save();

        CurrentOutlet::set($this->owner, $this->outletB->id);
        Livewire::test(ListProducts::class)
            ->set('activeTab', 'sold_out')
            ->assertCanSeeTableRecords([$product]);

        CurrentOutlet::set($this->owner, $this->outletA->id);
        Livewire::test(ListProducts::class)
            ->set('activeTab', 'sold_out')
            ->assertCanNotSeeTableRecords([$product]);
    });
});
