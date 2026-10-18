<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateCreditProgram;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\PointEntryType;
use App\Enums\ReceivableEntryType;
use App\Enums\StampEntryType;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberStamp;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ReceivableEntry;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Member\StampCards;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Skenario Phase 3 — satu hari member Rina di outlet JKT01 (ADR 0013 member, ADR 0023 kasbon).
 *
 * Outlet: service 5%, pajak 11% (exclusive), pembulatan ke atas ke kelipatan Rp100. Croissant 24.000 (stok 10), Donat 12.000.
 * Aturan poin bawaan: Rp10.000 dasar = 1 poin × pengali tier; dasar = subtotal − semua diskon order − bagian dibayar poin.
 * Tukar: 1 poin = Rp100, maks. 50% tagihan, min. 50 poin. Tier Silver ≥ 50.000 belanja 12 bln (poin ×2, diskon 5%),
 * Gold ≥ 200.000 (poin ×3, diskon 10%). Kartu stempel "Roti" 3 stempel = 1 gratis (Croissant & Donat).
 * Kasbon aktif, batas Rp100.000.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 03:00:00'); // 10.00 WIB
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    $this->owner = User::factory()->owner()->create();
    $this->donut = Product::factory()->create(['name' => 'Donat', 'price' => '12000.00']);
    $this->supervisorToken = userToken($this->pos->supervisor, $this->pos->device);
});

afterEach(fn () => Carbon::setTestNow());

/** Request API kasir; konteks tenant dipulihkan setelahnya agar assert model berjalan seperti biasa. */
function mjApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();
    $response = $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
    TenantContext::set($test->pos->tenantId);

    return $response;
}

/** POST /checkout untuk member; $payments = [[kategori, nominal], ...]. */
function mjCheckout(object $test, string $memberId, array $items, array $payments, array $extra = []): TestResponse
{
    $methods = PaymentMethod::query()->get()->keyBy(fn ($m) => $m->category->value);

    return mjApi($test, 'POST', '/checkout', [
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $memberId,
        'items' => $items,
        'payments' => array_map(fn (array $p) => ['id' => uuid(), 'payment_method_id' => $methods[$p[0]]->id, 'amount' => $p[1]], $payments),
        ...$extra,
    ]);
}

function mjStamps(object $test, Member $member): int
{
    return app(StampCards::class)->balances($member->refresh())[$test->program->id] ?? 0;
}

it('hari member: daftar, poin, naik tier, tukar poin, stempel, voucher, kasbon & pelunasan, void sekali → saldo akhir & laporan cocok', function () {
    // ── 0. Owner menyalakan program member, tier, kartu stempel, voucher, dan kasbon (dashboard) ─────────────────
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(SaveMemberTiers::class)->handle([
        ['name' => 'Silver', 'min_spend_12m' => '50000', 'point_multiplier' => '2', 'discount_percent' => '5'],
        ['name' => 'Gold', 'min_spend_12m' => '200000', 'point_multiplier' => '3', 'discount_percent' => '10'],
    ]);
    $this->program = app(SaveStampProgram::class)->handle(null, [
        'name' => 'Roti', 'stamps_required' => 3, 'product_ids' => [$this->pos->croissant->id, $this->donut->id],
    ]);
    $voucher = app(SaveVoucher::class)->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
    app(UpdateCreditProgram::class)->handle(true, '100000');

    // ── 1. Kasir mendaftarkan Rina lewat API; kiriman ganda = satu member ───────────────────────────────────────
    $register = ['id' => uuid(), 'name' => 'Rina', 'phone' => '0812 1111 2222', 'consent' => true];
    mjApi($this, 'POST', '/members', $register)->assertCreated()->assertJsonPath('meta.idempotent_replay', false);
    mjApi($this, 'POST', '/members', $register)->assertOk()->assertJsonPath('meta.idempotent_replay', true);
    expect(Member::query()->count())->toBe(1);
    $rina = Member::query()->findOrFail($register['id']);
    expect($rina->joined_outlet_id)->toBe($this->pos->outlet->id);
    app(SetMemberPin::class)->handle($rina, '4821');

    // ── 2. Order A: 2 Croissant tunai, belum ada tier ─────────────────────────────────────────────────────────
    // 48.000 → service 2.400 → pajak 11% × 50.400 = 5.544 → 55.944 → 56.000
    $a = mjCheckout($this, $rina->id, [['product_id' => $this->pos->croissant->id, 'qty' => 2]], [['cash', 56000]])
        ->assertCreated()->assertJsonPath('data.grand_total', '56000.00')->json('data.id');
    $rina->refresh();
    // Poin floor(48.000 / 10.000) × 1 = 4; belanja 12 bln 56.000 ≥ 50.000 → Silver (berlaku transaksi berikutnya)
    expect(Order::query()->findOrFail($a)->points_earned)->toBe(4)
        ->and($rina->points_balance)->toBe(4)
        ->and($rina->tier?->name)->toBe('Silver')
        ->and(mjStamps($this, $rina))->toBe(2);

    // Bonus pendaftaran dari owner (penyesuaian manual) agar saldo cukup ditukar: 4 + 100 = 104
    app(AdjustMemberPoints::class)->handle($rina, 100, 'Bonus pendaftaran', $this->owner);

    // ── 3. Order B (Silver): 1 Croissant, bayar 100 poin (Rp10.000) + PIN, sisa tunai ──────────────────────────
    // 24.000 − diskon tier 5% (1.200) = 22.800 → service 1.140 → pajak 11% × 23.940 = 2.633,40 → 26.573,40 → 26.600
    // Maks. tukar 50% × 26.600 = 13.300 ≥ 10.000; tunai 26.600 − 10.000 = 16.600
    $b = mjCheckout($this, $rina->id, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], [['points', 10000], ['cash', 16600]], ['member_pin' => '4821'])
        ->assertCreated()->assertJsonPath('data.grand_total', '26600.00')->assertJsonPath('data.points_redeemed', 100)->json('data.id');
    // PIN salah ditolak, saldo tidak berubah
    assertApiError(mjCheckout($this, $rina->id, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], [['points', 10000], ['cash', 16600]], ['member_pin' => '0000']), 'INVALID_MEMBER_PROOF', 422);
    // Poin: dasar 22.800 − 10.000 dibayar poin = 12.800 → 1 × 2 (Silver) = 2; saldo 104 − 100 + 2 = 6
    expect(Order::query()->findOrFail($b)->points_earned)->toBe(2)
        ->and($rina->refresh()->points_balance)->toBe(6)
        ->and(mjStamps($this, $rina))->toBe(3);

    // ── 4. Order C: tukar kartu stempel — Croissant + Donat, Donat (termurah) gratis ───────────────────────────
    // 36.000 − gratis 12.000 = 24.000 − tier 5% (1.200) = 22.800 → sama dengan B → 26.600
    $c = mjCheckout($this, $rina->id, [
        ['product_id' => $this->pos->croissant->id, 'qty' => 1], ['product_id' => $this->donut->id, 'qty' => 1],
    ], [['cash', 26600]], ['stamp_program_ids' => [$this->program->id]])
        ->assertCreated()->assertJsonPath('data.stamp_free_total', '12000.00')->assertJsonPath('data.grand_total', '26600.00')->json('data.id');
    // Stempel: 3 − 3 + (2 item − 1 gratis) = 1; poin dasar 22.800 → 2 × 2 = 4; saldo 6 + 4 = 10
    expect(Order::query()->findOrFail($c)->points_earned)->toBe(4)
        ->and(mjStamps($this, $rina))->toBe(1)
        ->and($rina->refresh()->points_balance)->toBe(10);

    // ── 5. Order D: voucher HEMAT5 ──────────────────────────────────────────────────────────────────────────
    // 24.000 − voucher 5.000 = 19.000 − tier 5% (950) = 18.050 → service 902,50 → 18.952,50
    // → pajak 2.084,775 → 2.084,78 → 21.037,28 → 21.100
    $d = mjCheckout($this, $rina->id, [['product_id' => $this->pos->croissant->id, 'qty' => 1]], [['cash', 21100]], ['voucher_code' => 'hemat5'])
        ->assertCreated()->assertJsonPath('data.voucher_discount', '5000.00')->assertJsonPath('data.grand_total', '21100.00')->json('data.id');
    // Poin floor(18.050 / 10.000) × 2 = 2 → 12; stempel 1 + 1 = 2
    expect(Order::query()->findOrFail($d)->points_earned)->toBe(2)
        ->and($rina->refresh()->points_balance)->toBe(12)
        ->and(mjStamps($this, $rina))->toBe(2)
        ->and($voucher->refresh()->used_count)->toBe(1);

    // ── 6. Order E: kasbon 1 Donat ──────────────────────────────────────────────────────────────────────────
    // 12.000 − tier 5% (600) = 11.400 → service 570 → pajak 11% × 11.970 = 1.316,70 → 13.286,70 → 13.300
    $e = mjCheckout($this, $rina->id, [['product_id' => $this->donut->id, 'qty' => 1]], [['receivable', 13300]], ['member_pin' => '4821'])
        ->assertCreated()->assertJsonPath('data.grand_total', '13300.00')->json('data.id');
    // Poin floor(11.400 / 10.000) × 2 = 2 → 14; stempel 3; kasbon 13.300
    expect(Order::query()->findOrFail($e)->points_earned)->toBe(2)
        ->and($rina->refresh()->points_balance)->toBe(14)
        ->and($rina->receivable_balance)->toBe('13300.00')
        ->and(mjStamps($this, $rina))->toBe(3);

    // ── 7. Pelunasan sebagian Rp5.000 tunai (idempotent) → kasbon 8.300 ─────────────────────────────────────
    $repay = ['id' => uuid(), 'amount' => 5000, 'payment_method_id' => $this->pos->methods['cash']->id];
    mjApi($this, 'POST', "/members/{$rina->id}/receivable-payments", $repay)->assertCreated()->assertJsonPath('data.balance_after', '8300.00');
    mjApi($this, 'POST', "/members/{$rina->id}/receivable-payments", $repay)->assertOk()->assertJsonPath('meta.idempotent_replay', true);
    expect($rina->refresh()->receivable_balance)->toBe('8300.00');

    // ── 8. Order F: kasbon 2 Croissant + 1 Donat → naik Gold ────────────────────────────────────────────────
    // 60.000 − tier 5% (3.000) = 57.000 → service 2.850 → pajak 11% × 59.850 = 6.583,50 → 66.433,50 → 66.500
    // Kasbon 8.300 + 66.500 = 74.800 ≤ batas 100.000
    $f = mjCheckout($this, $rina->id, [
        ['product_id' => $this->pos->croissant->id, 'qty' => 2], ['product_id' => $this->donut->id, 'qty' => 1],
    ], [['receivable', 66500]], ['member_pin' => '4821'])->assertCreated()->assertJsonPath('data.grand_total', '66500.00')->json('data.id');
    $rina->refresh();
    // Belanja 12 bln 56.000 + 26.600 + 26.600 + 21.100 + 13.300 + 66.500 = 210.100 ≥ 200.000 → Gold
    // Poin floor(57.000 / 10.000) × 2 (tier Silver sebelum order) = 10 → 24; stempel 3 + 3 = 6
    expect(Order::query()->findOrFail($f)->points_earned)->toBe(10)
        ->and($rina->points_balance)->toBe(24)
        ->and($rina->receivable_balance)->toBe('74800.00')
        ->and($rina->spend_12m)->toBe('210100.00')
        ->and($rina->tier?->name)->toBe('Gold')
        ->and(mjStamps($this, $rina))->toBe(6)
        // Stok Croissant: 10 − 2 (A) − 1 (B) − 1 (C) − 1 (D) − 2 (F) = 3
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(3);

    // ── 9. Supervisor me-void order F dua kali → semua efek dibalik tepat sekali ─────────────────────────────
    mjApi($this, 'POST', "/orders/{$f}/void", ['reason' => 'Salah member'], $this->supervisorToken)->assertOk();
    mjApi($this, 'POST', "/orders/{$f}/void", ['reason' => 'Salah member'], $this->supervisorToken)->assertOk();

    expect(Order::query()->findOrFail($f)->status)->toBe(OrderStatus::Voided)
        ->and(MemberPointEntry::query()->where('order_id', $f)->where('type', PointEntryType::VoidReversal)->count())->toBe(1)
        ->and(MemberStamp::query()->where('order_id', $f)->where('type', StampEntryType::VoidReversal)->count())->toBe(1)
        ->and(ReceivableEntry::query()->where('order_id', $f)->where('type', '!=', ReceivableEntryType::Charge)->count())->toBe(1)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(5);

    // ── 10. Saldo akhir ─────────────────────────────────────────────────────────────────────────────────────
    $rina->refresh();
    // Poin 24 − 10 = 14; stempel 6 − 3 = 3; kasbon 74.800 − 66.500 = 8.300; belanja 210.100 − 66.500 = 143.600 → Silver
    expect($rina->points_balance)->toBe(14)
        ->and(mjStamps($this, $rina))->toBe(3)
        ->and($rina->receivable_balance)->toBe('8300.00')
        ->and($rina->spend_12m)->toBe('143600.00')
        ->and($rina->total_spent)->toBe('143600.00')
        ->and($rina->visit_count)->toBe(5)
        ->and($rina->tier?->name)->toBe('Silver');

    mjApi($this, 'GET', "/members/{$rina->id}")->assertOk()
        ->assertJsonPath('data.tier.name', 'Silver')
        ->assertJsonPath('data.receivable_balance', '8300.00')
        ->assertJsonPath('data.stamp_cards.0.balance', 3)
        ->assertJsonPath('data.stamp_cards.0.rewards_available', 1);
    mjApi($this, 'GET', "/members/{$rina->id}/points")->assertOk()->assertJsonPath('data.balance', 14);

    // Kas shift: 200.000 + tunai 56.000 + 16.600 + 26.600 + 21.100 = 320.300 + pelunasan 5.000 = 325.300
    mjApi($this, 'GET', "/shifts/{$this->pos->shift->id}/summary")->assertOk()
        ->assertJsonPath('data.summary.cash_sales', '120300.00')
        ->assertJsonPath('data.summary.cash_in', '5000.00')
        ->assertJsonPath('data.summary.expected_cash', '325300.00');

    // ── 11. Laporan member hari ini (owner) ─────────────────────────────────────────────────────────────────
    mjApi($this, 'GET', '/reports/members?from=2026-10-08&to=2026-10-08', token: userToken($this->owner))->assertOk()
        // Order selesai A–E (F di-void): 56.000 + 26.600 + 26.600 + 21.100 + 13.300 = 143.600
        ->assertJsonPath('data.summary.member_orders', 5)
        ->assertJsonPath('data.summary.member_revenue', '143600.00')
        ->assertJsonPath('data.summary.guest_orders', 0)
        ->assertJsonPath('data.summary.active_members', 1)
        ->assertJsonPath('data.summary.repeat_members', 1)
        ->assertJsonPath('data.summary.new_members', 1)
        // Diskon tier 1.200 + 1.200 + 950 + 600 = 3.950
        ->assertJsonPath('data.summary.member_discount', '3950.00')
        // Poin dari transaksi selesai 4 + 2 + 4 + 2 + 2 = 14; ditukar 100
        ->assertJsonPath('data.summary.points_earned', 14)
        ->assertJsonPath('data.summary.points_redeemed', 100)
        // Utang poin = saldo aktif 14 × Rp100
        ->assertJsonPath('data.summary.points_liability', 14)
        ->assertJsonPath('data.summary.points_liability_value', '1400.00')
        ->assertJsonPath('data.summary.voucher_uses', 1)
        ->assertJsonPath('data.summary.voucher_discount', '5000.00')
        ->assertJsonPath('data.summary.stamp_free_count', 1)
        ->assertJsonPath('data.summary.stamp_free_total', '12000.00')
        ->assertJsonPath('data.summary.receivable_total', '8300.00')
        ->assertJsonPath('data.summary.receivable_members', 1)
        ->assertJsonPath('data.top_members.0.member_name', 'Rina')
        ->assertJsonPath('data.top_members.0.tier', 'Silver')
        ->assertJsonPath('data.top_members.0.order_count', 5)
        ->assertJsonPath('data.top_members.0.revenue', '143600.00')
        ->assertJsonPath('data.top_members.0.points_earned', 14);
});

it('kasbon melebihi batas ditolak tanpa mengubah saldo, poin, maupun stok', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateCreditProgram::class)->handle(true, '100000');
    $rina = Member::factory()->create(['name' => 'Rina']);
    app(SetMemberPin::class)->handle($rina, '4821');

    // 4 Croissant: 96.000 → service 4.800 → pajak 11.088 → 111.888 → 111.900 > batas 100.000
    assertApiError(mjCheckout($this, $rina->id, [['product_id' => $this->pos->croissant->id, 'qty' => 4]], [['receivable', 111900]], ['member_pin' => '4821']), 'VALIDATION_ERROR', 422);

    expect($rina->refresh()->receivable_balance)->toBe('0.00')
        ->and($rina->points_balance)->toBe(0)
        ->and(Order::query()->count())->toBe(0)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10)
        ->and(PaymentMethod::query()->where('category', PaymentCategory::Receivable)->sole()->is_active)->toBeTrue();
});

it('isolasi tenant: kasir bisnis lain tidak bisa memakai member, poin, maupun kasbon Rina', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $rina = Member::factory()->create(['name' => 'Rina']);
    $other = posSetup();
    // Bisnis lain juga memakai program member & kasbon: penolakan harus karena isolasi, bukan program nonaktif
    TenantContext::run($other->tenantId, function () use ($other): void {
        app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]);
        app(UpdateCreditProgram::class)->handle(true, '100000');
    });
    TenantContext::set($this->pos->tenantId);

    freshAuth();
    assertApiError($this->withToken($other->token)->getJson("/api/v1/members/{$rina->id}", apiHeaders()), 'MEMBER_NOT_FOUND', 404);
    freshAuth();
    assertApiError($this->withToken($other->token)->postJson("/api/v1/members/{$rina->id}/receivable-payments", [
        'id' => uuid(), 'amount' => 1000, 'payment_method_id' => $other->methods['cash']->id,
    ], apiHeaders()), 'MEMBER_NOT_FOUND', 404);
    TenantContext::set($this->pos->tenantId);

    expect($rina->refresh()->receivable_balance)->toBe('0.00');
});
