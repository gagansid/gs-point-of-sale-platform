<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use App\Support\TenantContext;

/*
 * GSPOS-SEC-011 (docs/security/reports/2026-10-10-audit.md): mutasi order lewat API hanya
 * boleh untuk order di outlet perangkat (ADR 0010). User yang memegang beberapa outlet
 * (owner / manager multi-outlet) yang login di perangkat outlet B tidak boleh melunasi open
 * bill outlet A, karena uang masuk ke shift B sementara order & stok tercatat di outlet A.
 */
beforeEach(function () {
    $this->pos = posSetup();

    TenantContext::run($this->pos->tenantId, function (): void {
        $this->outletB = Outlet::factory()->create(['code' => 'BDG01']);
        $this->deviceB = Device::factory()->forOutlet($this->outletB)->create(['device_uid' => 'kasir-bdg']);
        $cashierB = User::factory()->cashier()->forOutlet($this->outletB)->create();
        $this->shiftB = Shift::factory()->forDevice($this->deviceB, $cashierB)->create();
        $this->owner = User::factory()->owner()->create();
    });

    // Open bill di outlet A oleh kasir A
    $this->billId = uuid();
    freshAuth();
    $this->withToken($this->pos->token)->putJson("/api/v1/orders/{$this->billId}", [
        'order_type' => 'dine_in', 'table_label' => 'Meja 1',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], apiHeaders())->assertSuccessful();
});

it('owner di perangkat outlet B tidak bisa melunasi open bill outlet A', function () {
    freshAuth();
    $response = $this->withToken(userToken($this->owner, $this->deviceB))
        ->postJson("/api/v1/orders/{$this->billId}/payments", [
            'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
        ], apiHeaders());

    assertApiError($response, 'NOT_FOUND', 404);

    $order = Order::allTenants()->findOrFail($this->billId);
    expect($order->shift_id)->not->toBe($this->shiftB->id)
        ->and($order->status->value)->toBe('open');
});

it('owner di perangkat outlet B tidak bisa mengubah open bill outlet A', function () {
    freshAuth();
    $response = $this->withToken(userToken($this->owner, $this->deviceB))->putJson("/api/v1/orders/{$this->billId}", [
        'order_type' => 'dine_in', 'table_label' => 'Meja 1',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 3]],
    ], apiHeaders());

    assertApiError($response, 'NOT_FOUND', 404);
    expect(Order::allTenants()->findOrFail($this->billId)->items()->sole()->qty)->toBe(1);
});

it('owner di perangkat outlet B tidak bisa void order outlet A', function () {
    freshAuth();
    $orderA = $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ], apiHeaders())->assertCreated()->json('data.id');

    freshAuth();
    assertApiError(
        $this->withToken(userToken($this->owner, $this->deviceB))->postJson("/api/v1/orders/{$orderA}/void", ['reason' => 'x'], apiHeaders()),
        'NOT_FOUND',
        404,
    );
    expect(Order::allTenants()->findOrFail($orderA)->status->value)->toBe('completed');
});

it('regresi: owner di perangkat outlet A tetap bisa melunasi open bill outlet A', function () {
    freshAuth();
    $this->withToken(userToken($this->owner, $this->pos->device))
        ->postJson("/api/v1/orders/{$this->billId}/payments", [
            'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
        ], apiHeaders())
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed');
});

it('regresi: owner tanpa perangkat (aplikasi owner) tetap bisa void order outlet mana pun yang dipegang', function () {
    freshAuth();
    $orderA = $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ], apiHeaders())->assertCreated()->json('data.id');

    freshAuth();
    $this->withToken(userToken($this->owner))->postJson("/api/v1/orders/{$orderA}/void", ['reason' => 'x'], apiHeaders())
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');
});
