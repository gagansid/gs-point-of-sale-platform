<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->pos = posSetup();
    $this->orderId = checkoutForVoid($this, $this->pos->token);
});

function voidOrder(object $test, array $body, ?string $token = null, ?string $id = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? $test->pos->token)->postJson('/api/v1/orders/'.($id ?? $test->orderId).'/void', $body, apiHeaders());
}

function checkoutForVoid(object $test, string $token, ?stdClass $pos = null): string
{
    freshAuth();
    $pos ??= $test->pos;

    return $test->withToken($token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => 2]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 60000]],
    ], apiHeaders())->assertCreated()->json('data.id');
}

it('kasir void dengan PIN supervisor: stok kembali, pembayaran di-void, approver tercatat', function () {
    expect(Product::allTenants()->find($this->pos->croissant->id)?->stock_qty)->toBe(8);

    voidOrder($this, ['reason' => 'Salah input', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456'])
        ->assertOk()
        ->assertJsonPath('message', 'Transaksi berhasil dibatalkan')
        ->assertJsonPath('data.status', 'voided')
        ->assertJsonPath('data.void_reason', 'Salah input')
        ->assertJsonPath('data.voided_by', $this->pos->cashier->id)
        ->assertJsonPath('data.void_approved_by', $this->pos->supervisor->id)
        ->assertJsonPath('data.payments.0.status', 'voided');

    expect(Product::allTenants()->find($this->pos->croissant->id)?->stock_qty)->toBe(10)
        ->and(StockMovement::allTenants()->where('type', StockMovementType::VoidReturn)->sole()->qty_change)->toBe(2);
});

it('kasir tanpa PIN → APPROVAL_REQUIRED', function () {
    assertApiError(voidOrder($this, ['reason' => 'Salah']), 'APPROVAL_REQUIRED', 403);
    expect(Order::allTenants()->find($this->orderId)?->status)->toBe(OrderStatus::Completed);
});

it('approve diri sendiri → SELF_APPROVAL_NOT_ALLOWED', function () {
    assertApiError(voidOrder($this, ['reason' => 'x', 'approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456']), 'SELF_APPROVAL_NOT_ALLOWED', 403);
});

it('PIN approver salah → INVALID_PIN; 5 kali → PIN_LOCKED', function () {
    foreach (range(1, 4) as $_) {
        assertApiError(voidOrder($this, ['reason' => 'x', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '000000']), 'INVALID_PIN', 401);
    }

    assertApiError(voidOrder($this, ['reason' => 'x', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '000000']), 'PIN_LOCKED', 423);
});

it('supervisor void sendiri tanpa PIN', function () {
    voidOrder($this, ['reason' => 'Komplain'], userToken($this->pos->supervisor))
        ->assertOk()
        ->assertJsonPath('data.void_approved_by', null);
});

it('alasan wajib', function () {
    assertApiError(voidOrder($this, ['reason' => ''], userToken($this->pos->supervisor)), 'VALIDATION_ERROR', 422);
});

it('void dua kali → hasil sama, stok tidak dikembalikan dua kali', function () {
    $token = userToken($this->pos->supervisor);
    voidOrder($this, ['reason' => 'x'], $token)->assertOk();
    voidOrder($this, ['reason' => 'y'], $token)->assertOk()->assertJsonPath('meta.idempotent_replay', true)->assertJsonPath('data.void_reason', 'x');

    expect(Product::allTenants()->find($this->pos->croissant->id)?->stock_qty)->toBe(10);
});

it('shift sudah ditutup → SHIFT_NOT_OPEN', function () {
    $this->pos->shift->forceFill(['status' => 'closed', 'open_device_key' => null])->save();

    assertApiError(voidOrder($this, ['reason' => 'x'], userToken($this->pos->supervisor)), 'SHIFT_NOT_OPEN', 409);
});

it('void open bill: tanpa pengembalian stok', function () {
    $billId = uuid();
    freshAuth();
    $this->withToken($this->pos->token)->putJson("/api/v1/orders/{$billId}", ['items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]]], apiHeaders())->assertCreated();

    voidOrder($this, ['reason' => 'Tamu batal'], userToken($this->pos->supervisor), $billId)->assertOk();

    expect(Product::allTenants()->find($this->pos->croissant->id)?->stock_qty)->toBe(8)
        ->and(StockMovement::allTenants()->where('type', StockMovementType::VoidReturn)->count())->toBe(0);
});

it('order void tidak dihitung di ringkasan shift', function () {
    voidOrder($this, ['reason' => 'x'], userToken($this->pos->supervisor))->assertOk();

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->pos->shift->id}/summary", apiHeaders())
        ->assertJsonPath('data.summary.order_count', 0)
        ->assertJsonPath('data.summary.cash_sales', '0.00');

    expect(Payment::allTenants()->sole()->status)->toBe(PaymentStatus::Voided);
});

it('isolasi: order tenant lain → 404', function () {
    $other = posSetup();
    $foreign = checkoutForVoid($this, $other->token, $other);

    assertApiError(voidOrder($this, ['reason' => 'x'], userToken($this->pos->supervisor), $foreign), 'NOT_FOUND', 404);
});
