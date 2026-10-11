<?php

declare(strict_types=1);

use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/*
 * GSPOS-BUG-002 (docs/security/reports/2026-10-10-audit.md): nominal tidak boleh melewati kapasitas
 * DECIMAL(15,2) (MySQL strict → 500, MariaDB non-strict → terpotong diam-diam), dan batas diskon
 * berformat ilmiah ("1e-7") tidak boleh membuat semua checkout berdiskon 500.
 */
beforeEach(function () {
    $this->pos = posSetup();
    $this->owner = TenantContext::run($this->pos->tenantId, fn () => User::factory()->owner()->create());
    $this->ownerToken = userToken($this->owner, $this->pos->device);
});

function bug002Checkout(object $test, array $items, array $override = []): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => '9999999999999.99']],
        ...$override,
    ], apiHeaders());
}

it('harga produk di atas batas ditolak (API)', function () {
    freshAuth();
    $response = $this->withToken($this->ownerToken)->postJson('/api/v1/products', [
        'name' => 'Mahal', 'price' => '1000000000.00',
    ], apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor('price', 'error.details');
});

it('harga tepat di batas diterima', function () {
    freshAuth();
    $this->withToken($this->ownerToken)->postJson('/api/v1/products', [
        'name' => 'Paling Mahal', 'price' => Money::MAX_PRICE,
    ], apiHeaders())->assertCreated();
});

it('total checkout melewati DECIMAL(15,2) → 422, bukan 500', function () {
    $product = TenantContext::run($this->pos->tenantId, fn () => Product::factory()->create(['price' => Money::MAX_PRICE]));
    // 11 baris × 999 × 999.999.999,99 ≈ 1,1 × 10^13 > 9.999.999.999.999,99
    $items = array_fill(0, 11, ['product_id' => $product->id, 'qty' => 999]);

    $response = bug002Checkout($this, $items);

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonPath('message', 'Total transaksi melebihi batas');
});

it('batas diskon berformat ilmiah ditolak validasi', function () {
    freshAuth();
    $response = $this->withToken(userToken($this->owner))->putJson('/api/v1/outlet', [
        'discount_limits' => ['cashier' => '1e-7', 'supervisor' => 25],
    ], apiHeaders());

    assertApiError($response, 'VALIDATION_ERROR', 422);
    $response->assertJsonValidationErrorFor('discount_limits.cashier', 'error.details');
});

it('data batas diskon lama yang rusak tidak membuat checkout berdiskon 500', function () {
    Outlet::allTenants()->whereKey($this->pos->outlet->id)->update(['discount_limits' => json_encode(['cashier' => 1.0E-7, 'supervisor' => 25])]);

    $response = bug002Checkout($this, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], [
        'discount' => ['type' => 'percent', 'value' => 5],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ]);

    // Batas rusak dibaca 0% → di atas batas, wajib approval (bukan 500)
    assertApiError($response, 'DISCOUNT_OVER_LIMIT', 422);
    $response->assertJsonPath('error.details.limit_percent', '0');
});
