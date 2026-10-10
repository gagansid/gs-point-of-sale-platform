<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\Outlet;
use App\Models\User;
use App\Support\TenantContext;

/*
 * Setelan "Tampilkan pembulatan di struk" (SPEC Q48): bawaan pembulatan digabung ke total.
 * Hanya tampilan struk; perhitungan & data order tidak berubah.
 */
beforeEach(function () {
    $this->pos = posSetup(); // pembulatan Rp100
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

    // 24.000 + 5% + 11% = 27.972 → dibulatkan 28.000 (pembulatan Rp28)
    $this->order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ]))['order'];
});

it('bawaan: struk tidak menampilkan baris pembulatan, total tetap sama', function () {
    expect($this->order->rounding)->not->toBe('0.00');

    $this->actingAs($this->owner)->get("/dashboard/orders/{$this->order->id}/receipt")
        ->assertOk()->assertDontSee('Pembulatan')->assertSee('Rp28.000');

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$this->order->id}/receipt", apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.outlet.show_rounding', false)
        ->assertJsonPath('data.totals.rounding', $this->order->rounding)
        ->assertJsonPath('data.totals.grand_total', '28000.00');
});

it('owner menyalakan setelan lewat API', function () {
    freshAuth();
    $this->withToken(userToken($this->owner))->putJson('/api/v1/outlet', ['receipt_show_rounding' => true], apiHeaders())
        ->assertOk()->assertJsonPath('data.receipt_show_rounding', true);

    expect(Outlet::query()->findOrFail($this->pos->outlet->id)->receipt_show_rounding)->toBeTrue();
});

it('setelan menyala: struk menampilkan baris pembulatan', function () {
    $this->pos->outlet->update(['receipt_show_rounding' => true]);

    $this->actingAs($this->owner)->get("/dashboard/orders/{$this->order->id}/receipt")
        ->assertOk()->assertSee('Pembulatan')->assertSee('Rp28');
});

it('nilai setelan harus boolean', function () {
    freshAuth();
    assertApiError(
        $this->withToken(userToken($this->owner))->putJson('/api/v1/outlet', ['receipt_show_rounding' => 'ya'], apiHeaders()),
        'VALIDATION_ERROR',
        422,
    );
});
