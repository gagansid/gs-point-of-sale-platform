<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * GSPOS-SEC-001 (docs/security/reports/2026-10-10-audit.md): replay idempotency POST /checkout
 * tidak boleh mengembalikan order yang tidak boleh dilihat pelaku (outlet lain / kasir lain, Q21).
 * Perilaku yang diharapkan sama dengan GET /orders/{id}: 404 NOT_FOUND tanpa data order.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        // Outlet B di bisnis yang sama
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01']);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $this->cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create();
        Shift::factory()->forDevice($this->deviceB, $this->cashierB)->create();

        // Kasir kedua di outlet A, perangkat & shift sendiri (order.view_own)
        $this->deviceA2 = Device::factory()->forOutlet($this->pos->outlet)->create(['device_uid' => 'kasir-2']);
        $this->cashierA2 = User::factory()->cashier()->forOutlet($this->pos->outlet)->create();
        Shift::factory()->forDevice($this->deviceA2, $this->cashierA2)->create();
    });

    $this->tokenB = userToken($this->cashierB, $this->deviceB);
    $this->tokenA2 = userToken($this->cashierA2, $this->deviceA2);
});

/** Checkout 1 croissant tunai; $id dipakai ulang untuk replay. */
function sec001Checkout(object $test, string $token, string $id): TestResponse
{
    freshAuth();

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => $id,
        'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 100000]],
    ], apiHeaders());
}

it('kontrol: GET /orders/{id} order outlet B oleh kasir outlet A = 404', function () {
    $orderB = sec001Checkout($this, $this->tokenB, uuid())->assertCreated()->json('data.id');

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson("/api/v1/orders/{$orderB}", apiHeaders()), 'NOT_FOUND', 404);
});

it('replay ID order outlet B oleh kasir outlet A ditolak 404 tanpa data order', function () {
    $orderB = sec001Checkout($this, $this->tokenB, uuid())->assertCreated()->json('data.id');

    $response = sec001Checkout($this, $this->pos->token, $orderB);

    assertApiError($response, 'NOT_FOUND', 404);
    $response->assertJsonMissingPath('data.items');
});

it('replay ID order kasir lain (outlet sama, shift lain) ditolak 404 untuk kasir view_own', function () {
    $orderA = sec001Checkout($this, $this->pos->token, uuid())->assertCreated()->json('data.id');

    $response = sec001Checkout($this, $this->tokenA2, $orderA);

    assertApiError($response, 'NOT_FOUND', 404);
    $response->assertJsonMissingPath('data.items');
});

it('regresi: replay order sendiri tetap 200 idempotent_replay', function () {
    $id = uuid();
    sec001Checkout($this, $this->pos->token, $id)->assertCreated();

    sec001Checkout($this, $this->pos->token, $id)
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('meta.idempotent_replay', true);
});

it('regresi: antrean offline dikirim ulang dari perangkat yang sama oleh kasir lain tetap 200', function () {
    $id = uuid();
    sec001Checkout($this, $this->pos->token, $id)->assertCreated();

    // Kasir A2 login di perangkat kasir-1 (shift dibuka kasir A), lalu antrean lama terkirim ulang
    sec001Checkout($this, userToken($this->cashierA2, $this->pos->device), $id)
        ->assertOk()
        ->assertJsonPath('meta.idempotent_replay', true);
});
