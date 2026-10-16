<?php

declare(strict_types=1);

use App\Actions\Member\SetMemberCreditLimit;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\CashMovementReason;
use App\Enums\PaymentCategory;
use App\Enums\ReceivableEntryType;
use App\Models\CashMovement;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ReceivableEntry;
use App\Models\Shift;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Kasbon member (ADR 0023, Q105–Q107). Croissant 24.000 → total 28.000 (service 5%, pajak 11%, pembulatan 100).
 * Batas bawaan Rp100.000.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::run($this->pos->tenantId, function (): void {
        app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
        app(UpdateCreditProgram::class)->handle(true, '100000');
        $this->member = Member::factory()->create(['name' => 'Rina']);
        app(SetMemberPin::class)->handle($this->member, '4821');
        $this->credit = PaymentMethod::query()->where('category', PaymentCategory::Receivable)->sole();
    });
});

function creditSale(object $test, array $override = [], int $qty = 1): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->postJson('/api/v1/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id, 'member_pin' => '4821',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->credit->id, 'amount' => 28000 * $qty]],
        ...$override,
    ], apiHeaders());
}

function creditPay(object $test, array $body = []): TestResponse
{
    freshAuth();

    return $test->withToken($test->pos->token)->postJson("/api/v1/members/{$test->member->id}/receivable-payments", [
        'id' => uuid(), 'amount' => 10000, 'payment_method_id' => $test->pos->methods['cash']->id, ...$body,
    ], apiHeaders());
}

it('checkout kasbon: saldo bertambah, ledger charge, detail & riwayat member', function () {
    creditSale($this)->assertCreated();

    expect($this->member->refresh()->receivable_balance)->toBe('28000.00')
        ->and(ReceivableEntry::allTenants()->sole()->type)->toBe(ReceivableEntryType::Charge);

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}", apiHeaders())
        ->assertJsonPath('data.receivable_balance', '28000.00')->assertJsonPath('data.credit_limit', '100000.00');
    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}/receivables", apiHeaders())
        ->assertOk()->assertJsonPath('data.entries.0.type', 'charge');
});

it('ditolak: tanpa bukti, tanpa member, melebihi batas, batas 0, kasbon nonaktif', function () {
    assertApiError(creditSale($this, ['member_pin' => null]), 'INVALID_MEMBER_PROOF', 422);
    assertApiError(creditSale($this, ['member_id' => null, 'member_pin' => null]), 'VALIDATION_ERROR', 422);
    // 4 croissant = 111.900 > 100.000
    assertApiError(creditSale($this, ['payments' => [['id' => uuid(), 'payment_method_id' => $this->credit->id, 'amount' => 111900]]], 4), 'VALIDATION_ERROR', 422);

    TenantContext::run($this->pos->tenantId, fn () => app(SetMemberCreditLimit::class)->handle($this->member, '0'));
    assertApiError(creditSale($this), 'VALIDATION_ERROR', 422);

    TenantContext::run($this->pos->tenantId, function (): void {
        app(SetMemberCreditLimit::class)->handle($this->member, null);
        app(UpdateCreditProgram::class)->handle(false, '100000');
    });
    assertApiError(creditSale($this), 'VALIDATION_ERROR', 422);
    expect($this->member->refresh()->receivable_balance)->toBe('0.00')->and(ReceivableEntry::allTenants()->count())->toBe(0);
});

it('void membalik kasbon; retur mengurangi kasbon, bukan uang', function () {
    $voided = creditSale($this)->json('data.id');
    $refunded = creditSale($this)->json('data.id');
    expect($this->member->refresh()->receivable_balance)->toBe('56000.00');

    freshAuth();
    $this->withToken(userToken($this->pos->supervisor, $this->pos->device))->postJson("/api/v1/orders/{$voided}/void", ['reason' => 'Salah'], apiHeaders())->assertOk();
    expect($this->member->refresh()->receivable_balance)->toBe('28000.00');

    $itemId = TenantContext::run($this->pos->tenantId, fn () => Order::query()->findOrFail($refunded)->items()->value('id'));
    freshAuth();
    $this->withToken(userToken($this->pos->supervisor, $this->pos->device))->postJson("/api/v1/orders/{$refunded}/refunds", [
        'id' => uuid(), 'reason' => 'Rusak', 'method' => 'cash', 'items' => [['order_item_id' => $itemId, 'qty' => 1]],
    ], apiHeaders())->assertCreated()->assertJsonPath('data.payout_amount', '0.00');

    expect($this->member->refresh()->receivable_balance)->toBe('0.00')
        ->and(CashMovement::allTenants()->count())->toBe(0);
});

it('pelunasan tunai: kas masuk shift, saldo berkurang, idempotent, tidak melebihi saldo', function () {
    creditSale($this);
    $id = uuid();

    creditPay($this, ['id' => $id, 'amount' => 20000])->assertCreated()->assertJsonPath('data.balance_after', '8000.00');
    creditPay($this, ['id' => $id, 'amount' => 20000])->assertOk()->assertJsonPath('meta.idempotent_replay', true);
    assertApiError(creditPay($this, ['amount' => 8000.01]), 'VALIDATION_ERROR', 422);
    assertApiError(creditPay($this, ['payment_method_id' => $this->credit->id]), 'VALIDATION_ERROR', 422);

    expect($this->member->refresh()->receivable_balance)->toBe('8000.00');
    $movement = CashMovement::allTenants()->sole();
    expect($movement->reason)->toBe(CashMovementReason::ReceivablePayment)->and($movement->amount)->toBe('20000.00');

    freshAuth();
    $this->withToken($this->pos->token)->getJson("/api/v1/shifts/{$this->pos->shift->id}/summary", apiHeaders())
        ->assertJsonPath('data.summary.cash_in', '20000.00');

    // Non-tunai: tanpa kas masuk
    creditPay($this, ['amount' => 8000, 'payment_method_id' => $this->pos->methods['qris']->id])->assertCreated();
    expect(CashMovement::allTenants()->count())->toBe(1)->and($this->member->refresh()->receivable_balance)->toBe('0.00');
});

it('isolasi: member tenant lain → 404; kasbon manual tidak bisa dipilih sebagai alasan kas', function () {
    $foreign = posSetup();
    $foreignMember = TenantContext::run($foreign->tenantId, fn () => Member::factory()->create());

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson("/api/v1/members/{$foreignMember->id}/receivable-payments", [
        'id' => uuid(), 'amount' => 1000, 'payment_method_id' => $this->pos->methods['cash']->id,
    ], apiHeaders()), 'MEMBER_NOT_FOUND', 404);

    freshAuth();
    assertApiError($this->withToken($this->pos->token)->postJson('/api/v1/cash-movements', [
        'id' => uuid(), 'type' => 'in', 'reason' => 'receivable', 'amount' => 1000,
    ], apiHeaders()), 'VALIDATION_ERROR', 422);
});

it('kasir web: halaman kasbon & pelunasan', function () {
    creditSale($this);
    webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::run($this->pos->tenantId, fn () => Shift::factory()->forDevice($this->pos->webDevice, $this->pos->cashier)->create());

    $this->get(route('cashier.receivables'))->assertOk()->assertSee('Rina')->assertSee('Rp28.000');
    $this->post(route('cashier.receivables.pay', $this->member->id), [
        'id' => (string) Str::uuid7(), 'amount' => '28000', 'payment_method_id' => $this->pos->methods['cash']->id,
    ])->assertRedirect(route('cashier.receivables'))->assertSessionHas('status', 'Pelunasan Rina Rp28.000 dicatat');

    expect($this->member->refresh()->receivable_balance)->toBe('0.00');
});
