<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\PayWithPointsFromPhone;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderStatus;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Member;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\MemberPortal;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/** Self-order S4 (ADR 0014): API aplikasi kasir, laporan kanal, bayar poin dari HP. Croissant 24.000 → 28.000. */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
});

function phoneOrder(object $test, ?Member $member = null, array $options = []): SelfOrder
{
    return app(SubmitSelfOrder::class)->handle(SelfOrderContext::resolve($test->table->qr_token), [
        'id' => uuid(), 'customer_name' => 'Andi', 'notes' => 'pedas',
        'items' => [['product_id' => $test->pos->coffee->id, 'qty' => 1, 'option_ids' => $options ?: [$test->pos->large->id], 'notes' => 'less ice']],
    ], $member, '127.0.0.1')['self_order'];
}

function soApi(object $test, string $method, string $uri, array $body = [])
{
    freshAuth();

    return $test->withToken($test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
}

it('GET /self-orders: pesanan masuk outlet perangkat dengan nama produk & opsi; jumlah belum dibayar', function () {
    $selfOrder = phoneOrder($this);
    TenantContext::run($this->pos->tenantId, fn () => app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, phoneOrder($this)));

    soApi($this, 'GET', '/self-orders')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $selfOrder->id)
        ->assertJsonPath('data.0.table.name', 'Meja 5')
        ->assertJsonPath('data.0.items.0.product_name', 'Es Kopi Susu')
        ->assertJsonPath('data.0.items.0.option_names', ['Large'])
        ->assertJsonPath('data.0.estimated_total', '31500.00')
        ->assertJsonPath('meta.pending_count', 1)
        ->assertJsonPath('meta.unpaid_count', 1);

    soApi($this, 'GET', '/orders?status=awaiting_payment&channel=self_order')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'self_order')->assertJsonPath('data.0.status', 'awaiting_payment');
});

it('terima, tolak, dan bayar lewat API; ditangani dua kali = 409', function () {
    $a = phoneOrder($this);
    $b = phoneOrder($this);

    $orderId = soApi($this, 'POST', "/self-orders/{$a->id}/accept")->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.table_label', 'Meja 5')->json('data.id');
    assertApiError(soApi($this, 'POST', "/self-orders/{$a->id}/accept"), 'ORDER_NOT_PENDING', 409);

    assertApiError(soApi($this, 'POST', "/self-orders/{$b->id}/reject", []), 'VALIDATION_ERROR', 422);
    soApi($this, 'POST', "/self-orders/{$b->id}/reject", ['reason' => 'Habis'])->assertOk()->assertJsonPath('data.status', 'rejected');

    soApi($this, 'POST', "/orders/{$orderId}/payments", ['payments' => [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000],
    ]])->assertCreated()->assertJsonPath('data.status', 'completed');
});

it('isolasi: pesanan bisnis lain tidak terlihat & tidak bisa diterima', function () {
    $selfOrder = phoneOrder($this);
    $other = posSetup();

    freshAuth();
    $this->withToken($other->token)->getJson('/api/v1/self-orders', apiHeaders())->assertOk()->assertJsonCount(0, 'data');
    freshAuth();
    assertApiError($this->withToken($other->token)->postJson("/api/v1/self-orders/{$selfOrder->id}/accept", [], apiHeaders()), 'NOT_FOUND', 404);
});

it('laporan kanal: kasir vs QR meja; API hanya report.view', function () {
    app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 50000]],
    ]));
    $order = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, phoneOrder($this));
    soApi($this, 'POST', "/orders/{$order->id}/payments", ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => 31500]]])->assertCreated();

    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set(null, $this->pos->outlet->id);
    $rows = collect(app(ReportService::class)->channels(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()))->keyBy('channel');
    expect($rows['pos']['order_count'])->toBe(1)->and($rows['pos']['revenue'])->toBe('28000.00')
        ->and($rows['self_order']['order_count'])->toBe(1)->and($rows['self_order']['revenue'])->toBe('31500.00');

    $owner = User::factory()->owner()->create();
    $today = CurrentOutlet::today();
    freshAuth();
    $this->withToken(userToken($owner))->getJson("/api/v1/reports/channels?from={$today}&to={$today}", apiHeaders())->assertOk()
        ->assertJsonPath('data.channels.1.channel', 'self_order');
    assertApiError(soApi($this, 'GET', "/reports/channels?from={$today}&to={$today}"), 'FORBIDDEN', 403);
});

it('member bayar dengan poin dari HP: maks. sesuai aturan, sisa tetap ditagih; lunas penuh bila poin cukup', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $member = Member::factory()->create(['name' => 'Rina']);
    app(AdjustMemberPoints::class)->handle($member, 1000, 'Saldo awal', User::factory()->owner()->create());

    $order = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, phoneOrder($this, $member));
    // 31.500 × 50% = 15.750 → 157 poin (Rp15.700)
    expect(PayWithPointsFromPhone::maxPoints($order, $member->refresh()))->toBe(157);

    $paid = app(PayWithPointsFromPhone::class)->handle($order, $member);
    expect($paid->status)->toBe(OrderStatus::AwaitingPayment)->and($paid->points_redeemed)->toBe(157)
        ->and($paid->paid_total)->toBe('15700.00')->and($member->refresh()->points_balance)->toBe(843)
        // Batas % sudah terpakai
        ->and(PayWithPointsFromPhone::maxPoints($paid, $member))->toBe(0);

    // Program mengizinkan 100%: order kedua lunas dengan poin
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id], ['max_redeem_percent' => '100']);
    $second = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, phoneOrder($this, $member));
    $selfOrderId = SelfOrder::query()->where('order_id', $second->id)->value('id');

    MemberPortal::guard()->login($member);
    $member->forceFill(['password' => 'rahasia123'])->save();
    session()->put(MemberPortal::SESSION_PASSWORD, MemberPortal::passwordFingerprint($member->refresh()));
    session()->put('self_orders.'.$this->table->id, [$selfOrderId]);
    SelfOrderContext::set(SelfOrderContext::resolve($this->table->qr_token));

    Livewire::test(OrderPage::class)
        ->assertSee('Pakai 315 poin')
        ->call('payWithPoints', $selfOrderId)
        ->assertSet('error', null)
        ->assertSee('Lunas');

    expect(Order::query()->find($second->id)->status)->toBe(OrderStatus::Completed);
});
