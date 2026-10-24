<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

/**
 * Konvensi menu kedua panel (layout.md §2–§3, SPEC Q132 & Q135): susunan grup tetap, satu ikon untuk satu menu,
 * tidak ada grup berisi satu menu. Dilihat dari pengguna dengan akses penuh (owner / super admin).
 */

/** @return array<string, list<string>> grup → label menu (grup tanpa nama = "—") */
function navigationTree(): array
{
    return collect(Filament::getNavigation())
        ->mapWithKeys(fn (NavigationGroup $group): array => [
            (string) ($group->getLabel() ?? '—') => collect($group->getItems())->map(fn (NavigationItem $item): string => (string) $item->getLabel())->values()->all(),
        ])->all();
}

/** @return list<string> ikon semua menu */
function navigationIcons(): array
{
    return collect(Filament::getNavigation())
        ->flatMap(fn (NavigationGroup $group) => $group->getItems())
        ->map(fn (NavigationItem $item): string => is_object($icon = $item->getIcon()) ? $icon->value : (string) $icon)
        ->values()->all();
}

function assertNavigationConventions(): void
{
    $icons = navigationIcons();
    expect($icons)->toHaveCount(count(array_unique($icons)), 'Ada ikon menu kembar: '.implode(', ', array_diff_assoc($icons, array_unique($icons))));

    foreach (navigationTree() as $group => $items) {
        if ($group !== '—') {
            expect(count($items))->toBeGreaterThan(1, "Grup {$group} hanya berisi satu menu");
        }
    }
}

it('panel admin: susunan grup & menu sesuai standar', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(Admin::factory()->withTwoFactor()->create(), 'admin');

    expect(navigationTree())->toBe([
        '—' => ['Dasbor'],
        'Pelanggan' => ['Bisnis', 'Calon pelanggan'],
        'Langganan' => ['Paket', 'Add-on', 'Tagihan', 'Pembayaran langganan', 'Bank'],
        'Aplikasi' => ['Versi aplikasi', 'Pengumuman'],
        'Pengaturan' => ['Pendaftaran', 'Keamanan', 'Admin'],
        'Sistem' => ['Backup', 'Antrean', 'Pemeliharaan', 'Log', 'Riwayat perubahan'],
    ]);

    assertNavigationConventions();
});

it('panel dashboard (owner): susunan grup & konvensi menu', function () {
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::factory()->create();
    TenantContext::set($outlet->tenant_id);
    $owner = User::factory()->owner()->create(['tenant_id' => $outlet->tenant_id, 'web_cashier_access' => true]);
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    expect(array_keys(navigationTree()))->toBe(['—', 'Transaksi', 'Katalog', 'Stok', 'Pelanggan', 'Pengaturan', 'Akun'])
        ->and(navigationTree()['—'])->toBe(['Beranda', 'Laporan'])
        ->and(navigationTree()['Akun'])->toBe(['Profil saya', 'Langganan', 'Riwayat perubahan']);

    assertNavigationConventions();
});
