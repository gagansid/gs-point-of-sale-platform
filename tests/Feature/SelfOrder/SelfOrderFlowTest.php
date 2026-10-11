<?php

declare(strict_types=1);

use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\AddPayment;
use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\ExpireSelfOrders;
use App\Actions\SelfOrder\RejectSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Self-order S2 (ADR 0014, Q68–Q71): kirim dari HP → kasir terima/tolak → bayar di kasir / kedaluwarsa.
 * posSetup: Croissant 24.000 (stok 10) → 28.000 dengan service 5%, pajak 11%, pembulatan Rp100.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'payment_timeout_minutes' => 15]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    RateLimiter::clear('self-order-ip:10.0.0.1');
});

afterEach(fn () => Carbon::setTestNow());

function selfContext(object $test): SelfOrderContext
{
    return SelfOrderContext::resolve($test->table->qr_token) ?? throw new RuntimeException('QR tidak valid');
}

function submitSelf(object $test, array $override = [], ?Member $member = null, string $ip = '10.0.0.1'): SelfOrder
{
    return app(SubmitSelfOrder::class)->handle(selfContext($test), [
        'id' => uuid(), 'customer_name' => 'Andi',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 2]],
        ...$override,
    ], $member, $ip)['self_order'];
}

function failsWith(Closure $call, string $code): BusinessException
{
    try {
        $call();
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe($code);

        return $e;
    }

    throw new RuntimeException("Seharusnya gagal dengan {$code}");
}

it('kirim dari HP: menunggu kasir, perkiraan total, stok belum dipotong; kirim ganda = pesanan sama', function () {
    $id = uuid();
    $first = submitSelf($this, ['id' => $id]);
    $again = submitSelf($this, ['id' => $id]);

    expect($first->status)->toBe(SelfOrderStatus::Pending)
        ->and($first->estimated_total)->toBe('55900.00')
        ->and($again->id)->toBe($first->id)
        ->and(SelfOrder::query()->count())->toBe(1)
        ->and(Order::query()->count())->toBe(0)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);
});

it('ditolak: self-order nonaktif / di luar jam, nama kosong, produk tidak ada, batas pending per meja & per IP', function () {
    failsWith(fn () => submitSelf($this, ['items' => [['product_id' => uuid(), 'qty' => 1]]]), 'VALIDATION_ERROR');
    expect(fn () => submitSelf($this, ['customer_name' => '']))->toThrow(ValidationException::class);

    submitSelf($this);
    submitSelf($this);
    submitSelf($this);
    failsWith(fn () => submitSelf($this), 'TOO_MANY_REQUESTS');

    // IP lain, meja lain: dibatasi 10/jam per IP
    $other = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 6']);
    $this->table = $other;
    foreach (range(1, 3) as $_) {
        submitSelf($this, ip: '10.0.0.9');
    }
    RateLimiter::increment('self-order-ip:10.0.0.9', 7);
    failsWith(fn () => submitSelf($this, ip: '10.0.0.9'), 'TOO_MANY_REQUESTS');

    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => false]);
    failsWith(fn () => submitSelf($this, ip: '10.0.0.2'), 'SELF_ORDER_DISABLED');
});

it('kasir menerima: order menunggu bayar di shift kasir, kanal self_order, meja; bayar tunai → selesai & stok dipotong', function () {
    $selfOrder = submitSelf($this, ['notes' => 'tanpa gula']);

    $order = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $selfOrder);

    expect($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->channel)->toBe(OrderChannel::SelfOrder)
        ->and($order->shift_id)->toBe($this->pos->shift->id)
        ->and($order->table_label)->toBe('Meja 5')
        ->and($order->dining_table_id)->toBe($this->table->id)
        ->and($order->notes)->toBe('Atas nama Andi · tanpa gula')
        ->and($order->grand_total)->toBe('55900.00')
        ->and($order->order_number)->not->toBeEmpty()
        ->and($selfOrder->refresh()->status)->toBe(SelfOrderStatus::Accepted)
        ->and($selfOrder->order_id)->toBe($order->id)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);

    failsWith(fn () => app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $selfOrder), 'ORDER_NOT_PENDING');

    $paid = app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $order, [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => '60000', 'tendered' => null, 'reference' => null],
    ])['order'];

    expect($paid->status)->toBe(OrderStatus::Completed)->and($paid->change_total)->toBe('4100.00')
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(8);
});

it('member & voucher dari HP dihitung ulang saat diterima; poin masuk saat lunas', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(SaveVoucher::class)->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
    $member = Member::factory()->create(['name' => 'Rina']);

    $selfOrder = submitSelf($this, ['voucher_code' => 'hemat5'], $member);
    $order = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $selfOrder);

    expect($order->member_id)->toBe($member->id)->and($order->voucher_discount)->toBe('5000.00');

    app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $order, [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => '100000', 'tendered' => null, 'reference' => null],
    ]);
    // 48.000 − 5.000 = 43.000 → 4 poin
    expect($order->refresh()->points_earned)->toBe(4);
});

it('kasir menolak dengan alasan; pesanan ditolak tidak bisa diterima', function () {
    $selfOrder = submitSelf($this);

    failsWith(fn () => app(RejectSelfOrder::class)->handle($this->pos->cashier, $this->pos->outlet->id, $selfOrder, ' '), 'VALIDATION_ERROR');
    app(RejectSelfOrder::class)->handle($this->pos->cashier, $this->pos->outlet->id, $selfOrder, 'Croissant habis');

    expect($selfOrder->refresh()->status)->toBe(SelfOrderStatus::Rejected)->and($selfOrder->reject_reason)->toBe('Croissant habis');
    failsWith(fn () => app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $selfOrder), 'ORDER_NOT_PENDING');
});

it('kedaluwarsa: pesanan menunggu & order belum dibayar melewati batas waktu', function () {
    Carbon::setTestNow('2026-10-08 03:00:00');
    $waiting = submitSelf($this);
    $accepted = app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, submitSelf($this));

    Carbon::setTestNow('2026-10-08 03:16:00');
    failsWith(fn () => app(AcceptSelfOrder::class)->handle($this->pos->cashier, $this->pos->device, $waiting), 'ORDER_EXPIRED');
    expect($waiting->refresh()->status)->toBe(SelfOrderStatus::Expired);

    expect(app(ExpireSelfOrders::class)->handle())->toBe(1)
        ->and($accepted->refresh()->status)->toBe(OrderStatus::Expired);

    failsWith(fn () => app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $accepted, [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => '60000', 'tendered' => null, 'reference' => null],
    ]), 'ORDER_EXPIRED');
});

it('isolasi: kasir bisnis lain tidak melihat pesanan; perangkat outlet lain → tidak ditemukan', function () {
    $selfOrder = submitSelf($this);
    $other = posSetup();
    TenantContext::set($other->tenantId);

    expect(SelfOrder::query()->find($selfOrder->id))->toBeNull();
    failsWith(fn () => app(AcceptSelfOrder::class)->handle($other->cashier, $other->device, $selfOrder), 'NOT_FOUND');
    failsWith(fn () => app(RejectSelfOrder::class)->handle($other->cashier, $other->outlet->id, $selfOrder, 'x'), 'NOT_FOUND');

    TenantContext::set($this->pos->tenantId);
    expect($selfOrder->refresh()->status)->toBe(SelfOrderStatus::Pending);
});
