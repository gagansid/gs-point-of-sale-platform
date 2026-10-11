<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Facades\Filament;

/*
 * GSPOS-SEC-023 (docs/security/reports/2026-10-10-audit.md): setelah pindah outlet, pengguna hanya
 * dikembalikan ke halaman di panel ini (skema, host, port sama; path di bawah /dashboard). Selain itu
 * ke beranda panel — bukan perbandingan awalan string yang bisa diakali.
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $outlet = Outlet::factory()->create();
    TenantContext::set($outlet->tenant_id);
    $owner = User::factory()->owner()->create(['tenant_id' => $outlet->tenant_id]);
    $this->actingAs($owner);
    CurrentOutlet::set($owner);
    $this->home = Filament::getPanel('dashboard')->getUrl();
});

it('kembali ke halaman asal di panel', function () {
    $this->post(route('filament.dashboard.switch-outlet', ['outlet' => 'all']), [], ['Referer' => 'http://localhost/dashboard/products?page=2'])
        ->assertRedirect('http://localhost/dashboard/products?page=2');
});

it('Referer di luar panel diarahkan ke beranda panel', function (string $referer) {
    $this->post(route('filament.dashboard.switch-outlet', ['outlet' => 'all']), [], ['Referer' => $referer])
        ->assertRedirect($this->home);
})->with([
    'domain penyerang berawalan host panel' => 'http://localhost.evil.test/dashboard/products',
    'path berawalan path panel' => 'http://localhost/dashboard-evil/phish',
    'skema berbeda' => 'https://localhost/dashboard/products',
    'port berbeda' => 'http://localhost:8080/dashboard/products',
    'di luar path panel' => 'http://localhost/admin/tenants',
    'domain lain' => 'http://evil.test/',
]);
