<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Models\Tenant;
use App\Support\TenantAppearance;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/** Tema bisnis di halaman pelanggan: meja & member (ADR 0027 §6, SPEC Q137, Q128). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-senja']);
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
});

function setAppearance(string $tenantId, array $preferences): void
{
    Tenant::query()->whereKey($tenantId)->update(['preferences' => json_encode($preferences)]);
}

it('bawaan: warna gs.POS, tanpa override token, kelas .so-* dan navigasi bawah', function () {
    $this->get($this->table->orderUrl())->assertOk()
        ->assertSee('<meta name="theme-color" content="#2d4282">', false)
        ->assertDontSee('gs-tenant-appearance', false)
        ->assertSee('class="so-body"', false)
        ->assertSee('class="so-tabbar"', false)
        ->assertSee('Meja 5')
        ->assertDontSee('self-order-', false)
        ->assertDontSee('member-card', false);
});

it('warna & mode bisnis diterapkan di halaman meja dan halaman member', function (string $mode) {
    setAppearance($this->pos->tenantId, [TenantAppearance::COLOR => 'teal', TenantAppearance::THEME_MODE => $mode]);

    foreach ([$this->table->orderUrl(), '/m/kopi-senja/login'] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('<meta name="theme-color" content="#0f766e">', false)
            ->assertSee('<style id="gs-tenant-appearance">:root{--primary:#0f766e;', false)
            ->assertSee("const mode = '{$mode}'", false)
            ->assertSee('resources/css/self-order.css', false);
    }
})->with(['light', 'dark', 'system']);

it('header: logo bisnis bila ada, selain itu inisial; tenant lain tidak memengaruhi', function () {
    $this->get($this->table->orderUrl())->assertOk()->assertSee('<span class="so-brand-logo" aria-hidden="true">', false);

    setAppearance($this->pos->tenantId, [TenantAppearance::LOGO => 'tenants/'.$this->pos->tenantId.'/logo.png']);
    $this->get($this->table->orderUrl())->assertOk()->assertSee('/storage/tenants/'.$this->pos->tenantId.'/logo.png', false);

    // Tema bisnis lain tidak bocor ke bisnis ini
    $other = posSetup(openShift: false);
    setAppearance($other->tenantId, [TenantAppearance::COLOR => 'brick']);
    $this->get($this->table->orderUrl())->assertOk()->assertDontSee('#b42318', false);
});

it('mode menu Kotak/Baris dari localStorage dipasang sebelum render', function () {
    $this->get($this->table->orderUrl())->assertOk()
        ->assertSee("localStorage.getItem('gspos.so.view')", false)
        ->assertSee('data-view="grid"', false)
        ->assertSee('data-view="list"', false);
});

it('CSS halaman pelanggan tanpa hex hard-coded selain token status & putih', function () {
    $css = (string) file_get_contents(resource_path('css/self-order.css'));
    preg_match_all('/^\s*(--[\w-]+)?\s*:?\s*.*?(#[0-9a-fA-F]{3,8})\b/m', $css, $matches, PREG_SET_ORDER);

    $offending = collect($matches)
        ->reject(fn (array $m): bool => strtolower($m[2]) === '#ffffff' || str_starts_with(trim($m[0]), '--so-'))
        ->map(fn (array $m): string => trim($m[0]))
        ->values()->all();

    expect($offending)->toBe([]);
});

it('keadaan khusus: QR tidak dikenal / meja nonaktif / kode pendek salah = halaman ramah 404 tanpa nama bisnis', function () {
    $this->get('/order/t/'.str_repeat('A', 32))->assertNotFound()->assertSee('Kode QR tidak aktif')->assertSee('class="so-body"', false);

    $this->table->forceFill(['is_active' => false])->save();
    $this->get($this->table->orderUrl())->assertNotFound()->assertSee('Kode QR tidak aktif')
        ->assertDontSee($this->pos->outlet->name)->assertDontSee('Meja 5');
    $this->get('/t/ZZZZZZ')->assertNotFound()->assertSee('Kode QR tidak aktif');
});

it('keadaan khusus: self-order mati & di luar jam layanan memakai empty-state yang sama; banner koneksi putus tersedia', function () {
    $this->get($this->table->orderUrl())->assertOk()->assertSee('wire:offline', false)->assertSee('Koneksi terputus');

    Carbon::setTestNow('2026-10-08 05:00:00'); // 12.00 WIB
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'opens_at' => '16:00', 'closes_at' => '22:00']);
    $this->get($this->table->orderUrl())->assertOk()->assertSee('Belum bisa memesan dari Meja 5')->assertSee('Di luar jam layanan');

    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => false]);
    $this->get($this->table->orderUrl())->assertOk()->assertSee('Pesan dari meja belum aktif di outlet ini');
    Carbon::setTestNow();
});
