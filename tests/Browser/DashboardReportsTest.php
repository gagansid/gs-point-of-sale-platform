<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\User;
use App\Support\TenantContext;
use Laravel\Dusk\Browser;

/** E2E dashboard (R9): login owner → Beranda → Laporan, angka dari transaksi nyata. */
it('owner login dashboard lalu membuka Beranda dan Laporan', function () {
    $pos = posSetup();
    $owner = TenantContext::run($pos->tenantId, function () use ($pos): User {
        app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
            'id' => uuid(), 'order_type' => 'takeaway',
            'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
            'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 50000]],
        ]));

        return User::factory()->owner()->create(['email' => 'owner@e2e.test', 'password' => 'rahasia-123']);
    });

    $this->browse(function (Browser $browser): void {
        $browser->visit('/dashboard/login')
            ->type('input[type="email"]', 'owner@e2e.test')
            ->type('input[type="password"]', 'rahasia-123')
            ->press('Masuk')
            ->waitForText('Beranda')
            ->waitForText('Rp28.000')
            ->visit('/dashboard/reports')
            ->waitForText('Laporan')
            ->assertSee('Rp28.000');
    });

    expect($owner->fresh()?->last_login_at)->not->toBeNull();
});
