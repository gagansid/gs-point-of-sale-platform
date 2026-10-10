<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;

/*
 * Detail order bergaya nota + cetak ulang struk dari dashboard (orders/{order}/receipt, order.reprint).
 */
beforeEach(function () {
    Filament::setCurrentPanel('dashboard');
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    TenantContext::set($this->pos->tenantId);

    $this->order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order'];
});

it('detail order tampil sebagai nota dengan metode bayar dan tombol cetak ulang', function () {
    $this->actingAs($this->owner)->get("/dashboard/orders/{$this->order->id}")
        ->assertOk()
        ->assertSee($this->order->order_number)
        ->assertSee('Croissant')
        ->assertSee('Tunai')
        ->assertSee('Cetak ulang struk');
});

it('owner membuka struk siap cetak tanpa cache', function () {
    $this->actingAs($this->owner)->get("/dashboard/orders/{$this->order->id}/receipt")
        ->assertOk()
        ->assertSee($this->order->order_number)
        ->assertSee('Croissant')
        ->assertSee('Cetak ulang')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('struk order tenant lain → 404', function () {
    $other = posSetup();
    $otherOwner = User::factory()->owner()->create(['tenant_id' => $other->tenantId]);

    $this->actingAs($otherOwner)->get("/dashboard/orders/{$this->order->id}/receipt")->assertNotFound();
});

it('tanpa login dialihkan ke halaman masuk', function () {
    $this->get("/dashboard/orders/{$this->order->id}/receipt")->assertRedirect();
});
