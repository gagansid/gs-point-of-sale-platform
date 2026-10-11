<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Laravel\Dusk\Browser;

/**
 * E2E kasir web (R9, Q54): login username → buka shift → tambah produk → bayar tunai → struk.
 * Data dibuat di database/dusk.sqlite (lihat DuskTestCase).
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    TenantContext::run($this->pos->tenantId, function (): void {
        Tenant::query()->whereKey($this->pos->tenantId)->update(['slug' => 'kopi-e2e']);
        $this->pos->cashier->forceFill(['username' => 'budi', 'password' => 'rahasia-123', 'web_cashier_access' => true])->save();
    });
});

it('kasir login, buka shift, jual 1 produk tunai, lihat kembalian & struk', function () {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/cashier/login')
            ->assertSee('Masuk ke kasir')
            ->click('label:has(input[value="username"]) span')
            ->type('business', 'kopi-e2e')
            ->type('login', 'budi')
            ->type('password', 'rahasia-123')
            ->press('Masuk')
            // Satu outlet → langsung dipilih; belum ada shift → halaman buka shift
            ->waitForText('Buka shift')
            ->type('opening_cash', '100000')
            ->press('Buka shift')
            ->waitForText('Shift berhasil dibuka')
            ->waitForText('Croissant')
            ->click('.pos-tile-main')
            ->waitForText('Bayar Rp')
            ->press('.pos-pay-btn')
            ->waitFor('#pay-title')
            ->type('input[wire\\:model\\.live\\.debounce\\.400ms="payments.0.tendered"]', '50000')
            ->waitForText('Kembalian')
            ->press('Selesaikan')
            ->waitForText('Transaksi berhasil')
            ->assertSee('Kembalian')
            ->assertSee('Cetak struk');
    });

    $order = Order::allTenants()->sole();
    expect($order->status->value)->toBe('completed')
        ->and($order->grand_total)->toBe('28000.00');

    $this->browse(fn (Browser $browser) => $browser->visit("/cashier/orders/{$order->id}/receipt")
        ->assertSee($order->order_number)
        ->assertSee('Croissant'));
});

it('menu Kasir di dashboard: owner masuk ulang dengan kata sandi lalu kembali ke dashboard saat keluar', function () {
    $owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create(['email' => 'owner@e2e.test', 'password' => 'rahasia-123']));

    $this->browse(function (Browser $browser) use ($owner): void {
        $browser->loginAs($owner, 'web')
            ->visit('/dashboard')
            ->waitForText('Beranda')
            ->assertSeeLink('Kasir')
            ->visit('/cashier/confirm')
            ->assertSee('Masuk sebagai')
            ->click('label:has(input[value="password"]) span')
            ->type('password', 'rahasia-123')
            ->press('Masuk kasir')
            ->waitForText('Buka shift')
            ->press('Keluar')
            ->waitForText('Beranda')
            ->assertPathIs('/dashboard');
    });
});
