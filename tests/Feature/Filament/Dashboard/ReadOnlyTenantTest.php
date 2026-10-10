<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Dashboard\Resources\Products\ProductResource;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Trial/langganan habis → panel hanya-baca (ADR 0009, SPEC Q32).
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->owner = User::factory()->owner()->create();
    $this->product = TenantContext::run($this->owner->tenant_id, fn () => Product::factory()->create(['name' => 'Es Teh']));

    Tenant::query()->whereKey($this->owner->tenant_id)->update(['subscription_ends_at' => now()->subDay()]);
    $this->owner->refresh();
    $this->actingAs($this->owner);
    // Bisnis selalu punya minimal satu outlet (stok & zona waktu per outlet, ADR 0010)
    Outlet::factory()->create(['tenant_id' => $this->owner->tenant_id]);
    // Sama seperti SetDashboardTenant untuk request Livewire di test
    TenantContext::set($this->owner->tenant_id, readOnly: true);
});

it('owner tetap bisa login & melihat produk, dengan banner hanya-baca', function () {
    $this->get('/dashboard/products')
        ->assertOk()
        ->assertSee('Es Teh')
        ->assertSee('Data tetap aman dan bisa dilihat');
});

it('tambah, ubah, hapus, dan aksi baris disembunyikan/ditolak', function () {
    expect(ProductResource::canCreate())->toBeFalse()
        ->and(ProductResource::canEdit($this->product))->toBeFalse()
        ->and(ProductResource::canDelete($this->product))->toBeFalse()
        ->and($this->owner->can('product.manage'))->toBeFalse()
        ->and($this->owner->hasPermission('product.manage'))->toBeTrue()
        ->and($this->owner->can('report.view'))->toBeTrue();

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$this->product])
        ->assertActionHidden(TestAction::make('markSoldOut')->table($this->product));

    $this->get("/dashboard/products/{$this->product->id}/edit")->assertForbidden();
});

it('pengaman model: simpan & hapus data tenant ditolak SUBSCRIPTION_EXPIRED', function () {
    expect(fn () => $this->product->update(['name' => 'Ubah']))->toThrow(BusinessException::class)
        ->and(fn () => $this->product->delete())->toThrow(BusinessException::class)
        ->and($this->product->refresh()->name)->toBe('Es Teh');
});

it('tenant aktif tidak terpengaruh dan menampilkan sisa hari trial', function () {
    Tenant::query()->whereKey($this->owner->tenant_id)->update([
        'status' => TenantStatus::Trial,
        'subscription_ends_at' => now()->addDays(5),
    ]);
    $this->owner->refresh();
    TenantContext::set($this->owner->tenant_id);

    expect(ProductResource::canCreate())->toBeTrue();
    $this->get('/dashboard/products')->assertOk()->assertSee('Trial tersisa 5 hari');
});
