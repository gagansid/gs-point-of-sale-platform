<?php

declare(strict_types=1);

use App\Actions\Member\IssueBirthdayVouchers;
use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateBirthdayVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Order\AddPayment;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Actions\Order\VoidOrder;
use App\Enums\VoucherSource;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\MemberProgramSettings;
use App\Filament\Dashboard\Resources\Vouchers\Pages\ManageVouchers;
use App\Filament\Dashboard\Resources\Vouchers\VoucherResource;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\MemberTier;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherRedemption;
use App\Services\Member\TierEvaluator;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Voucher M7 (ADR 0013, Q66). Croissant 24.000; service 5%, pajak 11%, pembulatan Rp100.
 * Urutan potongan: diskon order manual → voucher → diskon member/tier.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->member = Member::factory()->create(['name' => 'Rina', 'birth_day' => 17, 'birth_month' => 8]);
});

afterEach(fn () => Carbon::setTestNow());

function promo(array $data = []): Voucher
{
    return app(SaveVoucher::class)->handle(null, ['code' => 'hemat5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000', ...$data]);
}

function voucherSale(object $test, array $override = [], int $qty = 1): Order
{
    return app(CheckoutOrder::class)->handle($test->pos->cashier, $test->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'member_id' => $test->member->id, 'voucher_code' => 'HEMAT5',
        'items' => [['product_id' => $test->pos->croissant->id, 'qty' => $qty]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $test->pos->methods['cash']->id, 'amount' => 200000]],
        ...$override,
    ]))['order'];
}

function voucherFails(Closure $sale, string $reason): void
{
    try {
        $sale();
        test()->fail("Seharusnya ditolak: {$reason}");
    } catch (BusinessException $e) {
        expect($e->errorCode)->toBe('VOUCHER_INVALID')->and($e->status)->toBe(422)->and($e->details['reason'] ?? null)->toBe($reason);
    }
}

it('kode promo nominal tanpa member: potongan, pemakaian tercatat, kode tidak peka huruf besar', function () {
    $voucher = promo();
    // 24.000 − 5.000 = 19.000 → service 950 → pajak 2.194,5 → 22.144,5 → 22.200
    $order = voucherSale($this, ['member_id' => null, 'voucher_code' => 'hemat5']);

    expect($order->voucher_discount)->toBe('5000.00')
        ->and($order->voucher_code)->toBe('HEMAT5')
        ->and($order->discount_total)->toBe('5000.00')
        ->and($order->grand_total)->toBe('22200.00')
        ->and($voucher->refresh()->used_count)->toBe(1)
        ->and(VoucherRedemption::query()->sole()->amount)->toBe('5000.00');
});

it('urutan: diskon order manual → voucher persen (maks.) → diskon member', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id], ['member_discount_percent' => '10']);
    promo(['code' => 'SETENGAH', 'type' => 'percent', 'value' => '50', 'max_discount' => '5000']);

    $order = voucherSale($this, ['voucher_code' => 'SETENGAH', 'discount' => ['type' => 'fixed', 'value' => '2000']]);

    // 24.000 − 2.000 = 22.000; voucher 50% = 11.000 → maks. 5.000; member 10% × 17.000 = 1.700
    expect($order->order_discount)->toBe('2000.00')
        ->and($order->voucher_discount)->toBe('5000.00')
        ->and($order->member_discount)->toBe('1700.00')
        ->and($order->discount_total)->toBe('8700.00')
        // Voucher & diskon member tidak dihitung ke batas diskon kasir (2.000 = 8,3%)
        ->and($order->approved_by)->toBeNull()
        // Poin dari dasar setelah semua potongan: 15.300 → 1 poin
        ->and($order->points_earned)->toBe(1);
});

it('ditolak dengan alasan: tidak ada, nonaktif, belum mulai, kedaluwarsa, minimal belanja, khusus member, tier, outlet', function () {
    voucherFails(fn () => voucherSale($this), 'not_found');

    $voucher = promo(['min_spend' => '30000']);
    voucherFails(fn () => voucherSale($this), 'min_spend');
    expect(voucherSale($this, qty: 2)->voucher_discount)->toBe('5000.00');

    $voucher->forceFill(['min_spend' => '0', 'is_active' => false])->save();
    voucherFails(fn () => voucherSale($this), 'inactive');

    $voucher->forceFill(['is_active' => true, 'starts_at' => now()->addDay()])->save();
    voucherFails(fn () => voucherSale($this), 'not_started');

    $voucher->forceFill(['starts_at' => null, 'ends_at' => now()->subMinute()])->save();
    voucherFails(fn () => voucherSale($this), 'expired');

    $voucher->forceFill(['ends_at' => null, 'member_only' => true])->save();
    voucherFails(fn () => voucherSale($this, ['member_id' => null]), 'member_only');

    app(SaveMemberTiers::class)->handle([['name' => 'Gold', 'min_spend_12m' => '1000000', 'point_multiplier' => '1', 'discount_percent' => '0']]);
    $voucher->forceFill(['tier_ids' => [MemberTier::query()->value('id')]])->save();
    voucherFails(fn () => voucherSale($this), 'tier');

    $other = Outlet::factory()->create(['tenant_id' => $this->pos->tenantId]);
    $voucher->forceFill(['tier_ids' => null, 'outlet_ids' => [$other->id]])->save();
    voucherFails(fn () => voucherSale($this), 'outlet');

    // Outlet dikecualikan dari program member: semua voucher tidak berlaku
    $voucher->forceFill(['outlet_ids' => null])->save();
    app(UpdateMemberProgram::class)->handle(true, [$other->id]);
    voucherFails(fn () => voucherSale($this), 'outlet');
});

it('kuota total & per member; void mengembalikan kuota; kiriman ulang tidak memakai dua kali', function () {
    $voucher = promo(['quota' => 1]);
    $id = uuid();
    $order = voucherSale($this, ['id' => $id]);
    voucherSale($this, ['id' => $id]);

    expect($voucher->refresh()->used_count)->toBe(1)->and(VoucherRedemption::query()->count())->toBe(1);
    voucherFails(fn () => voucherSale($this), 'quota_exhausted');

    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Batal', null);
    expect($voucher->refresh()->used_count)->toBe(0)
        ->and(VoucherRedemption::query()->sole()->status)->toBe(VoucherRedemption::VOIDED);

    // Kuota kembali; kuota per member 1 berlaku untuk member yang sama
    $voucher->forceFill(['quota' => null, 'per_member_quota' => 1])->save();
    voucherSale($this);
    voucherFails(fn () => voucherSale($this), 'member_quota_exhausted');
    voucherFails(fn () => voucherSale($this, ['member_id' => null]), 'member_only');
});

it('open bill: kuota dicek ulang saat lunas', function () {
    promo(['quota' => 1]);
    $billId = uuid();
    app(SaveOpenBill::class)->handle($this->pos->cashier, $this->pos->device, $billId, OpenBillData::fromArray([
        'member_id' => $this->member->id, 'voucher_code' => 'HEMAT5',
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ]));
    // Ubah item tanpa field voucher: voucher lama tetap
    app(SaveOpenBill::class)->handle($this->pos->cashier, $this->pos->device, $billId, OpenBillData::fromArray([
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]],
    ]));
    $bill = Order::query()->findOrFail($billId);
    expect($bill->voucher_discount)->toBe('5000.00');

    voucherSale($this);

    expect(fn () => app(AddPayment::class)->handle($this->pos->cashier, $this->pos->device, $bill->refresh(), [
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => '100000', 'tendered' => null, 'reference' => null],
    ]))->toThrow(BusinessException::class, 'Kuota voucher sudah habis');
    expect($bill->refresh()->status->value)->toBe('open');
});

it('voucher ulang tahun: dibuat sekali di bulan lahir, hanya untuk pemiliknya, hilang setelah dipakai', function () {
    app(UpdateBirthdayVoucher::class)->handle(['enabled' => true, 'type' => 'percent', 'value' => '20', 'max_discount' => '10000', 'min_spend' => '0']);
    $other = Member::factory()->create(['birth_month' => 9]);

    Carbon::setTestNow('2026-08-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(1)
        ->and(app(IssueBirthdayVouchers::class)->handle())->toBe(0);

    $voucher = Voucher::query()->sole();
    expect($voucher->source)->toBe(VoucherSource::Birthday)
        ->and($voucher->member_id)->toBe($this->member->id)
        ->and($voucher->code)->toBeNull()
        // Akhir Agustus WIB = 31 Agustus 16.59.59 UTC
        ->and($voucher->ends_at->toDateTimeString())->toBe('2026-08-31 16:59:59');

    voucherFails(fn () => voucherSale($this, ['member_id' => $other->id, 'voucher_code' => null, 'voucher_id' => $voucher->id]), 'not_owner');

    // 20% × 24.000 = 4.800
    $order = voucherSale($this, ['voucher_code' => null, 'voucher_id' => $voucher->id]);
    expect($order->voucher_discount)->toBe('4800.00')->and($order->voucher_code)->toBe('Voucher ulang tahun');

    $this->withToken($this->pos->token)->getJson("/api/v1/members/{$this->member->id}", apiHeaders())
        ->assertOk()->assertJsonCount(0, 'data.vouchers');

    // Bulan berikutnya: tidak ada voucher baru (bukan bulan lahir)
    Carbon::setTestNow('2026-09-01 01:00:00');
    expect(app(IssueBirthdayVouchers::class)->handle())->toBe(1)
        ->and(Voucher::query()->latest('created_at')->first()?->member_id)->toBe($other->id);
});

it('isolasi tenant: kode & ID voucher bisnis lain tidak ditemukan', function () {
    $voucher = promo();
    $other = posSetup();
    TenantContext::set($other->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$other->outlet->id]);

    $sale = fn (array $data) => app(CheckoutOrder::class)->handle($other->cashier, $other->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', ...$data,
        'items' => [['product_id' => $other->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $other->methods['cash']->id, 'amount' => 100000]],
    ]));

    voucherFails(fn () => $sale(['voucher_code' => 'HEMAT5']), 'not_found');
    voucherFails(fn () => $sale(['voucher_id' => $voucher->id]), 'not_found');

    // Kode sama boleh dipakai bisnis lain (unik per bisnis)
    expect(promo()->tenant_id)->toBe($other->tenantId);
});

it('API: cek kode, checkout dengan voucher, validasi gagal', function () {
    promo(['min_spend' => '30000']);
    $api = fn (string $method, string $uri, array $body = []) => $this->withToken($this->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());

    $api('GET', '/vouchers/check?code=hemat5')->assertOk()
        ->assertJsonPath('data.code', 'HEMAT5')->assertJsonPath('data.min_spend', '30000.00');
    $response = $api('GET', '/vouchers/check?code=hemat5&subtotal=10000');
    assertApiError($response, 'VOUCHER_INVALID', 422);
    $response->assertJsonPath('error.details.reason', 'min_spend');
    assertApiError($api('GET', '/vouchers/check?code=TIDAKADA'), 'VOUCHER_INVALID', 422);

    $body = [
        'id' => uuid(), 'order_type' => 'takeaway', 'voucher_code' => 'HEMAT5', 'voucher_id' => uuid(),
        'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 2]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ];
    assertApiError($api('POST', '/checkout', $body), 'VALIDATION_ERROR', 422);

    unset($body['voucher_id']);
    $api('POST', '/checkout', $body)->assertCreated()
        ->assertJsonPath('data.voucher.code', 'HEMAT5')
        ->assertJsonPath('data.voucher_discount', '5000.00');
    $api('GET', "/orders/{$body['id']}/receipt")->assertOk()->assertJsonPath('data.totals.voucher_code', 'HEMAT5');
});

it('dashboard: owner membuat kode promo & mengatur voucher ulang tahun; manager ditolak', function () {
    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    Livewire::test(ManageVouchers::class)
        ->callAction(TestAction::make('create')->table(), ['code' => 'weekend-15', 'name' => 'Akhir pekan', 'type' => 'percent', 'value' => '15', 'max_discount' => '20000'])
        ->assertHasNoActionErrors();
    expect(Voucher::query()->sole()->code)->toBe('WEEKEND-15');

    // Kode sama ditolak; persen > 100 ditolak
    Livewire::test(ManageVouchers::class)
        ->callAction(TestAction::make('create')->table(), ['code' => 'WEEKEND-15', 'name' => 'Lagi', 'type' => 'fixed', 'value' => '1000'])
        ->assertNotified('Kode voucher sudah dipakai');
    expect(fn () => promo(['code' => 'PERSEN', 'type' => 'percent', 'value' => '150']))->toThrow(ValidationException::class);
    expect(Voucher::query()->count())->toBe(1);

    Livewire::test(MemberProgramSettings::class)
        ->set('data.birthday.enabled', true)->set('data.birthday.type', 'fixed')->set('data.birthday.value', '25000')
        ->call('save')->assertNotified('Program member disimpan');
    expect(MemberSetting::current()->birthdayVoucher())->toMatchArray(['enabled' => true, 'type' => 'fixed', 'value' => '25000']);

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create());
    expect(VoucherResource::canViewAny())->toBeFalse();
    $this->get(VoucherResource::getUrl())->assertForbidden();
});

it('tier dievaluasi ulang tidak menghapus voucher; voucher tier berlaku setelah naik tier', function () {
    app(SaveMemberTiers::class)->handle([['name' => 'Gold', 'min_spend_12m' => '20000', 'point_multiplier' => '1', 'discount_percent' => '0']]);
    promo(['tier_ids' => [MemberTier::query()->value('id')]]);

    voucherFails(fn () => voucherSale($this), 'tier');
    voucherSale($this, ['voucher_code' => null]);
    app(TierEvaluator::class)->refresh($this->member->refresh());

    expect(voucherSale($this)->voucher_discount)->toBe('5000.00');
});
