<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * GSPOS-BUG-001 (docs/security/reports/2026-10-10-audit.md): order dengan total akhir 0
 * (diskon 100%) tetap bisa diselesaikan tanpa pembayaran; total > 0 tetap wajib dibayar.
 */
beforeEach(function () {
    $this->pos = posSetup();
    // Owner tidak dibatasi batas diskon (Q18)
    $owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create());
    $this->ownerToken = userToken($owner, $this->pos->device);
});

function bug001Checkout(object $test, array $override = []): TestResponse
{
    freshAuth();

    return $test->withToken($test->ownerToken)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'discount' => ['type' => 'percent', 'value' => 100],
        'payments' => [],
        ...$override,
    ], apiHeaders());
}

function bug001Pay(object $test, string $id, array $payments): TestResponse
{
    freshAuth();

    return $test->withToken($test->ownerToken)->postJson("/api/v1/orders/{$id}/payments", ['payments' => $payments], apiHeaders());
}

it('checkout diskon 100% tanpa pembayaran → selesai, stok terpotong', function () {
    bug001Checkout($this)
        ->assertCreated()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.grand_total', '0.00')
        ->assertJsonPath('data.paid_total', '0.00');

    expect(stockOf($this->pos->croissant->id)->stock_qty)->toBe(9);
});

it('checkout total > 0 tanpa pembayaran ditolak', function () {
    assertApiError(bug001Checkout($this, ['discount' => null]), 'VALIDATION_ERROR', 422);
});

it('checkout tanpa field payments ditolak validasi', function () {
    $body = ['id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]];
    freshAuth();
    $response = $this->withToken($this->ownerToken)->postJson('/api/v1/checkout', $body, apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor('payments', 'error.details');
});

it('open bill diskon 100% dilunasi dengan payments kosong; kiriman ulang = replay', function () {
    $id = uuid();
    freshAuth();
    $this->withToken($this->ownerToken)->putJson("/api/v1/orders/{$id}", [
        'order_type' => 'dine_in', 'table_label' => 'Meja 2',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'discount' => ['type' => 'percent', 'value' => 100],
    ], apiHeaders())->assertSuccessful()->assertJsonPath('data.status', 'open');

    bug001Pay($this, $id, [])->assertCreated()->assertJsonPath('data.status', 'completed');
    bug001Pay($this, $id, [])->assertOk()->assertJsonPath('meta.idempotent_replay', true);
});

it('open bill bersisa tagihan dengan payments kosong ditolak', function () {
    $id = uuid();
    freshAuth();
    $this->withToken($this->ownerToken)->putJson("/api/v1/orders/{$id}", [
        'order_type' => 'dine_in', 'table_label' => 'Meja 3',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], apiHeaders())->assertSuccessful();

    assertApiError(bug001Pay($this, $id, []), 'VALIDATION_ERROR', 422);
});
