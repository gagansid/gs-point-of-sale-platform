<?php

declare(strict_types=1);

use App\Filament\Dashboard\Resources\Products\Pages\ListProducts;
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
    TenantContext::set($owner->tenant_id);
});

it('menampilkan ringkasan singkat dan memuat halaman berikutnya dari server', function () {
    Product::factory()->count(25)->create();

    Livewire::test(ListProducts::class)
        ->assertSeeText('1–20 dari 25')
        ->assertSeeHtml('aria-label="Ke halaman 2"')
        ->call('gotoPage', 2)
        ->assertSeeText('21–25 dari 25')
        ->assertCountTableRecords(25);
});

it('tetap menampilkan pager walau hanya satu halaman', function () {
    Product::factory()->count(3)->create();

    Livewire::test(ListProducts::class)
        ->assertSeeText('1–3 dari 3')
        ->assertSeeHtml('class="fi-pagination-items"')
        ->assertSeeHtml('rel="next"');
});
