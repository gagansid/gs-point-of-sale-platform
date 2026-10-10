<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Employees\Pages\ManageEmployees;
use App\Filament\Dashboard\Resources\Products\Pages\CreateProduct;
use App\Filament\Dashboard\Resources\Products\Pages\EditProduct;
use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentTimezone;
use Livewire\Livewire;

/*
 * Audit tanggal dibuat & diubah di detail dan tabel (AuditInfo, AuditColumns).
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::factory()->create(['timezone' => 'Asia/Jakarta']);
    TenantContext::set($outlet->tenant_id);
    $this->owner = User::factory()->owner()->create();
    $this->actingAs($this->owner);
    CurrentOutlet::set($this->owner);
    // Di request nyata diisi SetDashboardTenant (zona outlet); Livewire::test tidak melewati middleware
    FilamentTimezone::set(CurrentOutlet::timezone());
});

it('halaman ubah produk menampilkan kartu Riwayat dalam zona waktu outlet', function () {
    $this->travelTo('2026-10-10 03:15:00'); // 10.15 WIB
    $product = Product::factory()->create();
    $this->travelTo('2026-10-10 05:00:00');
    $product->update(['name' => 'Kopi Baru']);

    Livewire::test(EditProduct::class, ['record' => $product->id])
        ->assertSee('Riwayat')
        ->assertSee('10 Okt 2026, 10.15')
        ->assertSee('Diubah');
});

it('form tambah tidak menampilkan audit', function () {
    Livewire::test(CreateProduct::class)->assertDontSee('Riwayat');
});

it('modal ubah karyawan menampilkan baris audit', function () {
    $budi = User::factory()->cashier()->create(['email' => 'budi@kopi.test']);

    Livewire::test(ManageEmployees::class)
        ->mountAction(TestAction::make('edit')->table($budi))
        ->assertSchemaComponentExists('auditInfo', 'mountedActionSchema0');
});

it('kolom Dibuat & Diubah tersedia di tabel tetapi tersembunyi bawaan', function () {
    Livewire::test(ListProducts::class)
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('updated_at')
        ->assertCanNotRenderTableColumn('created_at')
        ->assertCanNotRenderTableColumn('updated_at');
});
