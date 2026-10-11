<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VoidOrder;
use App\Enums\PaymentCategory;
use App\Enums\PointEntryType;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Member\MemberProof;
use App\Support\TenantContext;

/**
 * Penukaran poin (metode bayar Poin) & diskon member — M3 (ADR 0013, Q62–Q63).
 * Croissant 24.000 → total 28.000 (service 5%, pajak 11%, pembulatan Rp100). 1 poin = Rp100, maks. 50% tagihan.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->member = Member::factory()->create(['name' => 'Rina']);
    $this->owner = User::factory()->owner()->create();
    app(AdjustMemberPoints::class)->handle($this->member, 500, 'Saldo awal', $this->owner);
    app(SetMemberPin::class)->handle($this->member, '4821');
    $this->pointsMethod = PaymentMethod::query()->where('category', PaymentCategory::Points)->sole();
});

/** @param list<array<string, mixed>> $payments */
function redeemSale(object $test, array $payments, array $override = []): Order
{
    return app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id,
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
        'payments' => $payments,
        ...$override,
    ]))['order'];
}

function pointsPay(object $test, int $amount): array
{
    return ['id' => uuid(), 'payment_method_id' => $test->pointsMethod->id, 'amount' => $amount];
}

function cashPay(object $test, int $amount): array
{
    return ['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => $amount];
}

it('program aktif membuat metode bayar Poin; dinonaktifkan saat program mati; bukan bawaan bisnis baru', function () {
    expect($this->pointsMethod->is_active)->toBeTrue();

    app(UpdateMemberProgram::class)->handle(false, []);
    expect($this->pointsMethod->refresh()->is_active)->toBeFalse();

    $fresh = posSetup();
    expect(TenantContext::run($fresh->tenantId, fn () => PaymentMethod::query()->where('category', PaymentCategory::Points)->exists()))->toBeFalse();
});

it('bayar sebagian dengan poin + PIN: ledger redeem, saldo berkurang, poin didapat hanya dari bagian non-poin', function () {
    // 28.000: 10.000 poin (100 poin) + 18.000 tunai
    $order = redeemSale($this, [pointsPay($this, 10000), cashPay($this, 50000)], ['member_pin' => '4821']);

    $member = $this->member->refresh();
    expect($order->status->value)->toBe('completed')
        ->and($order->points_redeemed)->toBe(100)
        // Dasar 24.000 − 10.000 dibayar poin = 14.000 → 1 poin
        ->and($order->points_earned)->toBe(1)
        ->and($member->points_balance)->toBe(500 - 100 + 1)
        ->and(MemberPointEntry::query()->where('type', PointEntryType::Redeem)->sole()->points)->toBe(-100);
});

it('kode QR dinamis halaman member diterima sekali saja', function () {
    $code = MemberProof::current($this->member);

    redeemSale($this, [pointsPay($this, 5000), cashPay($this, 50000)], ['member_code' => $code]);

    expect(fn () => redeemSale($this, [pointsPay($this, 5000), cashPay($this, 50000)], ['member_code' => $code]))
        ->toThrow(BusinessException::class, 'Kode QR member salah atau sudah kedaluwarsa');
});

it('penukaran ditolak: tanpa bukti, PIN salah, melebihi saldo, di atas 50% tagihan, bukan kelipatan, di bawah minimal, tanpa member', function (array $override, int $amount, string $message) {
    $before = $this->member->refresh()->points_balance;

    expect(fn () => redeemSale($this, [pointsPay($this, $amount), cashPay($this, 50000)], $override))->toThrow(BusinessException::class, $message);
    expect($this->member->refresh()->points_balance)->toBe($before)
        ->and(Order::query()->count())->toBe(0);
})->with([
    'tanpa bukti' => [[], 5000, 'butuh kode QR atau PIN member'],
    'PIN salah' => [['member_pin' => '1111'], 5000, 'PIN member salah'],
    'di atas 50%' => [['member_pin' => '4821'], 14100, 'Maksimal 50% tagihan'],
    'bukan kelipatan' => [['member_pin' => '4821'], 5050, 'kelipatan'],
    'di bawah minimal' => [['member_pin' => '4821'], 4000, 'Minimal tukar 50 poin'],
    'tanpa member' => [['member_pin' => '4821', 'member_id' => null], 5000, 'Pasang member dulu'],
]);

it('saldo tidak cukup → INSUFFICIENT_POINTS', function () {
    app(AdjustMemberPoints::class)->handle($this->member, -450, 'Koreksi', $this->owner);   // sisa 50 poin

    expect(fn () => redeemSale($this, [pointsPay($this, 6000), cashPay($this, 50000)], ['member_pin' => '4821']))
        ->toThrow(BusinessException::class, 'Saldo poin Rina hanya 50');
});

it('PIN member terkunci setelah 5 kali salah', function () {
    foreach (range(1, 4) as $i) {
        try {
            redeemSale($this, [pointsPay($this, 5000), cashPay($this, 50000)], ['member_pin' => '1111']);
        } catch (BusinessException) {
        }
    }

    expect(fn () => redeemSale($this, [pointsPay($this, 5000), cashPay($this, 50000)], ['member_pin' => '1111']))
        ->toThrow(BusinessException::class)
        ->and(fn () => redeemSale($this, [pointsPay($this, 5000), cashPay($this, 50000)], ['member_pin' => '4821']))
        ->toThrow(BusinessException::class, 'PIN member terkunci');
});

it('void: poin yang ditukar dikembalikan, poin yang didapat ditarik', function () {
    $order = redeemSale($this, [pointsPay($this, 10000), cashPay($this, 50000)], ['member_pin' => '4821']);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Batal', null);
    app(VoidOrder::class)->handle($this->pos->supervisor, $order->refresh(), 'Batal', null);

    expect($this->member->refresh()->points_balance)->toBe(500);
});

it('open bill: lunasi dengan poin lewat tambah pembayaran', function () {
    $bill = app(SaveOpenBill::class)->handle($this->pos->cashier, $this->pos->device, uuid(), OpenBillData::fromArray([
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]], 'member_id' => $this->member->id,
    ]))['order'];

    $paid = app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $bill, CheckoutData::parsePayments([pointsPay($this, 14000), cashPay($this, 14000)]), null, '4821')['order'];

    expect($paid->status->value)->toBe('completed')->and($paid->points_redeemed)->toBe(140);
});

it('diskon member otomatis setelah diskon kasir, tidak dihitung ke batas diskon kasir', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id], ['member_discount_percent' => '10']);

    // 24.000 − diskon kasir 10% (2.400) = 21.600 − member 10% (2.160) = 19.440 → +5% +11% = 22.660,92 → 22.700
    $order = redeemSale($this, [cashPay($this, 50000)], ['discount' => ['type' => 'percent', 'value' => 10]]);

    expect($order->member_discount)->toBe('2160.00')
        ->and($order->order_discount)->toBe('2400.00')
        ->and($order->grand_total)->toBe('22700.00');
});

it('diskon member tidak berlaku di outlet dikecualikan', function () {
    app(UpdateMemberProgram::class)->handle(true, [], ['member_discount_percent' => '10']);

    expect(redeemSale($this, [cashPay($this, 50000)])->member_discount)->toBe('0.00');
});

it('API checkout: bayar poin dengan PIN; respons memuat points_redeemed', function () {
    freshAuth();
    $this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $this->member->id, 'member_pin' => '4821',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [pointsPay($this, 10000), cashPay($this, 50000)],
    ], apiHeaders())->assertCreated()->assertJsonPath('data.points_redeemed', 100);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $this->member->id, 'member_pin' => '0000',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [pointsPay($this, 10000), cashPay($this, 50000)],
    ], apiHeaders()), 'INVALID_MEMBER_PROOF', 422);
});

it('PIN member lemah ditolak', function (string $pin) {
    expect(fn () => app(SetMemberPin::class)->handle($this->member, $pin))->toThrow(BusinessException::class);
})->with(['1111', '1234', '4321', '12a4', '123']);
