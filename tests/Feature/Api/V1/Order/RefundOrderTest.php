<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Enums\CashMovementReason;
use App\Enums\PaymentCategory;
use App\Enums\PointEntryType;
use App\Enums\StockMovementType;
use App\Models\CashMovement;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Outlet;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Testing\TestResponse;

/**
 * Retur sebagian (ADR 0018, Q92–Q94). 2 Croissant (24.000) → subtotal 48.000 + service 5% + pajak 11%,
 * dibulatkan 100 = 55.900; retur 1 = 27.950.
 */
beforeEach(function () {
    $this->pos = posSetup();
    $this->order = refundSale($this);
});

function refundSale(object $test, int $qty = 2): Order
{
    return TenantContext::run($test->pos->tenantId, fn (): Order => app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 60000]],
    ]))['order']);
}

function refundItem(object $test): string
{
    return (string) TenantContext::run($test->pos->tenantId, fn () => $test->order->items()->value('id'));
}

function postRefund(object $test, array $body = [], ?string $token = null, ?string $orderId = null): TestResponse
{
    freshAuth();

    return $test->withToken($token ?? userToken($test->pos->supervisor, $test->pos->device))
        ->postJson('/api/v1/orders/'.($orderId ?? $test->order->id).'/refunds', [
            'id' => uuid(), 'reason' => 'Produk rusak', 'method' => 'cash',
            'items' => [['order_item_id' => refundItem($test), 'qty' => 1]],
            ...$body,
        ], apiHeaders());
}

it('retur 1 item tunai: nilai proporsional, kas keluar Retur, stok kembali, order & item diperbarui', function () {
    postRefund($this)->assertCreated()
        ->assertJsonPath('message', 'Retur berhasil dicatat')
        ->assertJsonPath('data.amount', '27950.00')
        ->assertJsonPath('data.payout_amount', '27950.00')
        ->assertJsonPath('data.items.0.qty', 1)
        ->assertJsonPath('data.approved_by', null);

    $order = $this->order->refresh();
    expect($order->refunded_total)->toBe('27950.00')
        ->and($order->items()->value('refunded_qty'))->toBe(1)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(9);

    $movement = CashMovement::allTenants()->sole();
    expect($movement->reason)->toBe(CashMovementReason::Refund)->and($movement->amount)->toBe('27950.00');
    expect(StockMovement::allTenants()->where('type', StockMovementType::Refund)->sole()->qty_change)->toBe(1);

    // Kas seharusnya: 200.000 + 55.900 − 27.950
    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->pos->shift->id}/summary", apiHeaders())
        ->assertJsonPath('data.summary.expected_cash', '227950.00');

    // Detail order memuat riwayat retur
    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/orders/{$order->id}", apiHeaders())
        ->assertJsonPath('data.refunded_total', '27950.00')
        ->assertJsonPath('data.items.0.refunded_qty', 1)
        ->assertJsonCount(1, 'data.refunds');
});

it('retur terakhir memakai sisa nilai order; setelah habis ditolak', function () {
    postRefund($this)->assertCreated();
    postRefund($this)->assertCreated()->assertJsonPath('data.amount', '27950.00');

    expect($this->order->refresh()->refunded_total)->toBe('55900.00')->and($this->order->isFullyRefunded())->toBeTrue();
    assertApiError(postRefund($this), 'VALIDATION_ERROR', 422);
});

it('kasir butuh PIN approver; diri sendiri ditolak; dengan PIN supervisor tercatat', function () {
    assertApiError(postRefund($this, token: $this->pos->token), 'APPROVAL_REQUIRED', 403);
    assertApiError(postRefund($this, ['approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456'], $this->pos->token), 'SELF_APPROVAL_NOT_ALLOWED', 403);
    expect(OrderRefund::allTenants()->count())->toBe(0);

    postRefund($this, ['approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '123456'], $this->pos->token)
        ->assertCreated()->assertJsonPath('data.approved_by', $this->pos->supervisor->id);
});

it('request ganda dengan id sama → satu retur', function () {
    $id = uuid();
    postRefund($this, ['id' => $id])->assertCreated();
    postRefund($this, ['id' => $id])->assertOk()->assertJsonPath('meta.idempotent_replay', true);

    expect(OrderRefund::allTenants()->count())->toBe(1)->and(CashMovement::allTenants()->count())->toBe(1);
});

it('validasi', function (array $body) {
    $body = json_decode(str_replace('{item}', refundItem($this), (string) json_encode($body)), true);
    assertApiError(postRefund($this, $body), 'VALIDATION_ERROR', 422);
    expect(OrderRefund::allTenants()->count())->toBe(0);
})->with([
    'qty melebihi terjual' => [['items' => [['order_item_id' => '{item}', 'qty' => 3]]]],
    'tanpa alasan' => [['reason' => '']],
    'tanpa item' => [['items' => []]],
    'qty 0 semua' => [['items' => [['order_item_id' => '{item}', 'qty' => 0]]]],
    'item order lain' => [['items' => [['order_item_id' => '01a00000-0000-7000-8000-000000000000', 'qty' => 1]]]],
    'cara tidak dikenal' => [['method' => 'points']],
]);

it('manual & tanpa restock: tanpa kas keluar, stok tidak berubah', function () {
    postRefund($this, ['method' => 'manual', 'restock' => false])->assertCreated();

    expect(CashMovement::allTenants()->count())->toBe(0)->and(stockOf($this->pos->croissant)->stock_qty)->toBe(8);
});

it('uang di laci tidak cukup → pilih manual', function () {
    $this->pos->shift->forceFill(['opening_cash' => '0.00'])->save();
    TenantContext::run($this->pos->tenantId, fn () => CashMovement::query()->create([
        'outlet_id' => $this->pos->outlet->id, 'shift_id' => $this->pos->shift->id, 'user_id' => $this->pos->cashier->id,
        'type' => 'out', 'reason' => 'deposit', 'amount' => '50000.00',
    ]));

    assertApiError(postRefund($this), 'VALIDATION_ERROR', 422);
    postRefund($this, ['method' => 'manual'])->assertCreated();
});

it('order belum selesai / void / lewat 30 hari / sudah diretur lalu void ditolak', function () {
    postRefund($this)->assertCreated();
    freshAuth();
    assertApiError($this->withToken(userToken($this->pos->supervisor, $this->pos->device))
        ->postJson("/api/v1/orders/{$this->order->id}/void", ['reason' => 'x'], apiHeaders()), 'VALIDATION_ERROR', 422);

    $old = refundSale($this, 1);
    TenantContext::run($this->pos->tenantId, fn () => $old->forceFill(['completed_at' => now()->subDays(31)])->save());
    $oldItem = TenantContext::run($this->pos->tenantId, fn () => $old->items()->value('id'));
    assertApiError(postRefund($this, ['items' => [['order_item_id' => $oldItem, 'qty' => 1]]], orderId: $old->id), 'VALIDATION_ERROR', 422);
});

it('tanpa shift terbuka di perangkat → SHIFT_NOT_OPEN', function () {
    $otherDevice = Device::factory()->forOutlet($this->pos->outlet)->create();

    assertApiError(postRefund($this, token: userToken($this->pos->supervisor, $otherDevice)), 'SHIFT_NOT_OPEN', 409);
});

it('isolasi: order tenant lain → 404; order outlet lain → 404', function () {
    $foreign = posSetup();
    $foreignOrder = refundSale((object) ['pos' => $foreign], 1);
    assertApiError(postRefund($this, orderId: $foreignOrder->id), 'NOT_FOUND', 404);

    $outlet2 = TenantContext::run($this->pos->tenantId, fn () => Outlet::factory()->create());
    $device2 = Device::factory()->forOutlet($outlet2)->create();
    $owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($device2, $owner)->create());
    assertApiError(postRefund($this, token: userToken($owner, $device2)), 'NOT_FOUND', 404);
    expect(OrderRefund::allTenants()->count())->toBe(0);
});

it('cari transaksi untuk retur per nomor: outlet perangkat, selesai, batas hari', function () {
    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/refundable-orders/{$this->order->order_number}", apiHeaders())
        ->assertOk()->assertJsonPath('data.id', $this->order->id);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->getJson('/api/v1/refundable-orders/TIDAK-ADA', apiHeaders()), 'NOT_FOUND', 404);
});

it('member: bagian poin kembali sebagai poin, poin didapat ditarik, belanja berkurang', function () {
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $member = Member::factory()->create();
    app(AdjustMemberPoints::class)->handle($member, 500, 'Saldo awal', User::factory()->owner()->create());
    app(SetMemberPin::class)->handle($member, '4821');
    $points = PaymentMethod::query()->where('category', PaymentCategory::Points)->sole();

    // 28.000: 10.000 poin (100 poin) + 18.000 tunai
    $this->order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $member->id, 'member_pin' => '4821',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $points->id, 'amount' => 10000], ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 20000]],
    ]))['order'];
    $earned = (int) MemberPointEntry::query()->where('order_id', $this->order->id)->where('type', PointEntryType::Earn)->sum('points');
    $balance = $member->refresh()->points_balance;

    postRefund($this)->assertCreated()
        ->assertJsonPath('data.amount', '28000.00')
        ->assertJsonPath('data.payout_amount', '18000.00')
        ->assertJsonPath('data.points_returned', 100)
        ->assertJsonPath('data.points_reversed', $earned);

    $member->refresh();
    expect($member->points_balance)->toBe($balance + 100 - $earned)
        ->and($member->total_spent)->toBe('0.00');
});
