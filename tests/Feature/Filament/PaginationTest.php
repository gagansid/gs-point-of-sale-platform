<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
use App\Filament\Shared\Layout;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
 * Paginasi tabel (resources/views/vendor/filament/components/pagination/index.blade.php):
 * ringkasan "1–20 dari 25" di kiri, pager selalu tampil, data per halaman diambil dari server.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    // Bisnis selalu punya minimal satu outlet (stok & zona waktu per outlet, ADR 0010)
    Outlet::factory()->create(['tenant_id' => $owner->tenant_id]);
    TenantContext::set($owner->tenant_id);
});

it('menampilkan ringkasan singkat dan memuat halaman berikutnya dari server', function () {
    Product::factory()->count(15)->create();

    Livewire::test(ListProducts::class)
        ->assertSeeText('1–10 dari 15')
        ->assertSeeHtml('aria-label="Ke halaman 2"')
        ->call('gotoPage', 2)
        ->assertSeeText('11–15 dari 15')
        ->assertCountTableRecords(15);
});

// Lewat HTTP agar konfigurasi tabel global (Layout::configureActions, dipasang saat panel boot) ikut berlaku
it('memakai pilihan baris per halaman 5, 10, 25, 50, 100', function () {
    Product::factory()->create();

    $html = $this->get('/dashboard/products')->assertOk()->getContent();

    foreach ([5, 10, 25, 50, 100] as $option) {
        expect($html)->toContain('<option value="'.$option.'">');
    }

    expect($html)->not->toContain('<option value="20">');
});

it('menyembunyikan badge filter hanya saat tidak ada filter aktif', function () {
    // Konfigurasi tabel global biasanya dipasang saat panel boot (tidak terjadi di test Livewire)
    Layout::configureActions();

    Livewire::test(ListProducts::class)
        ->assertSeeHtml('gs-filters-inactive')
        ->set('tableFilters.is_active.value', '1')
        ->assertDontSeeHtml('gs-filters-inactive');
});

it('mengingat pilihan baris per halaman setelah halaman dimuat ulang', function () {
    Layout::configureActions();
    Product::factory()->count(7)->create();

    Livewire::test(ListProducts::class)->set('tableRecordsPerPage', 5);

    Livewire::test(ListProducts::class)
        ->assertSet('tableRecordsPerPage', 5)
        ->assertSeeText('1–5 dari 7');
});

it('tetap menampilkan pager walau hanya satu halaman', function () {
    Product::factory()->count(3)->create();

    Livewire::test(ListProducts::class)
        ->assertSeeText('1–3 dari 3')
        ->assertSeeHtml('class="fi-pagination-items"')
        ->assertSeeHtml('rel="next"');
});
