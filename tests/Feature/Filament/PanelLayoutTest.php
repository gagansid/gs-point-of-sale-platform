<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Dashboard\Resources\Categories\Pages\ManageCategories;
use App\Filament\Shared\Layout;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Illuminate\Support\HtmlString;
use Livewire\Livewire;

/*
 * Kerangka panel gaya gs-task-tracker (docs/standards/ui/layout.md): logout lewat modal
 * konfirmasi, judul tabel di header card, breadcrumb berikon, teks konfirmasi aman dari XSS.
 */

describe('panel admin', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');
    });

    it('keluar lewat modal konfirmasi lalu sesi berakhir', function () {
        Livewire::test(Topbar::class)
            ->assertActionExists('logout', fn ($action): bool => $action->isConfirmationRequired())
            ->callAction('logout')
            ->assertRedirect(Filament::getLoginUrl());

        $this->assertGuest('admin');
    });

    it('slug tenant tidak ditampilkan di tabel tetapi tetap bisa dicari', function () {
        $tenant = Tenant::factory()->create(['name' => 'Kopi Pagi', 'slug' => 'slug-rahasia-xyz']);
        Tenant::factory()->create(['name' => 'Warung Lain']);

        Livewire::test(ListTenants::class)
            ->assertDontSeeText('slug-rahasia-xyz')
            ->searchTable('slug-rahasia')
            ->assertCanSeeTableRecords([$tenant])
            ->assertCountTableRecords(1);
    });

    it('judul "Daftar Tenant" dan tombol tambah berada di header card tabel', function () {
        Livewire::test(ListTenants::class)
            ->assertSee('Daftar Tenant')
            ->assertActionExists(TestAction::make('create')->table());
    });

    it('breadcrumb memberi ikon di item pertama dan meng-escape nama data', function () {
        $tenant = Tenant::factory()->create(['name' => '<b>Kopi</b>']);

        $breadcrumbs = Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
            ->instance()
            ->getBreadcrumbs();

        $first = array_values($breadcrumbs)[0];

        expect($first)->toBeInstanceOf(HtmlString::class)
            ->and($first->toHtml())->toContain('<svg')->toContain('Tenant')
            ->and(implode(' ', array_map(fn ($label) => $label instanceof HtmlString ? $label->toHtml() : e($label), $breadcrumbs)))
            ->not->toContain('<b>Kopi</b>');
    });
});

describe('panel dashboard', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('dashboard');
        $manager = User::factory()->manager()->create();
        $this->actingAs($manager);
        TenantContext::set($manager->tenant_id);
    });

    it('halaman kelola kategori memakai judul halaman sebagai breadcrumb berikon', function () {
        $breadcrumbs = Livewire::test(ManageCategories::class)->instance()->getBreadcrumbs();

        expect($breadcrumbs)->toHaveCount(1)
            ->and(array_values($breadcrumbs)[0]->toHtml())->toContain('<svg')->toContain('Kategori');
    });
});

it('teks konfirmasi meng-escape catatan', function () {
    $html = Layout::confirmText('Yakin?', '<script>alert(1)</script>')->toHtml();

    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>');
});
