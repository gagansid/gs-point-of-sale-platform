<?php

declare(strict_types=1);

use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Exceptions\BusinessException;
use App\Livewire\Cashier\PointOfSale;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Member;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\MemberPortal;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use App\Support\VoucherAttempts;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * GSPOS-SEC-027 (docs/security/reports/2026-10-23-audit.md): kode voucher tidak bisa ditebak beruntun lewat
 * halaman pesan dari meja (publik) maupun kasir web, dan pesan gagal publik tidak membedakan kode yang ada.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 7']);

    $save = app(SaveVoucher::class);
    $save->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);
    $save->handle(null, ['code' => 'LAMA', 'name' => 'Lama', 'type' => 'fixed', 'value' => '5000', 'ends_at' => now()->subDay()->toIso8601String()]);
    $save->handle(null, ['code' => 'BESAR', 'name' => 'Besar', 'type' => 'fixed', 'value' => '5000', 'min_spend' => '500000']);
});

function guessingOrderPage(object $test): Testable
{
    // ADR 0027 K1: program member aktif = pemesan wajib masuk sebagai member sebelum mengirim
    $member = Member::query()->where('phone', '+6281299990001')->first() ?? Member::factory()->create(['name' => 'Andi', 'phone' => '+6281299990001']);
    $member->forceFill(['password' => 'rahasia123'])->save();
    MemberPortal::guard()->login($member);
    session()->put(MemberPortal::SESSION_PASSWORD, MemberPortal::passwordFingerprint($member->refresh()));
    SelfOrderContext::set(SelfOrderContext::resolve($test->table->qr_token));

    return Livewire::test(OrderPage::class)
        ->call('addProduct', $test->pos->croissant->id)
        ->call('savePick')
        ->call('show', 'cart');
}

it('pesan dari meja: kode tidak ada, kedaluwarsa, dan di bawah minimal belanja dijawab pesan yang sama', function () {
    $page = guessingOrderPage($this);

    foreach (['TIDAKADA', 'LAMA', 'BESAR'] as $code) {
        $page->set('voucherCode', $code);
        expect($page->instance()->quote['error'])->toBe(VoucherAttempts::PUBLIC_INVALID_MESSAGE);
    }

    // Bayar ditolak dengan pesan seragam yang sama (ADR 0027: tagihan dibuat saat memilih metode bayar)
    $page->call('openPay')->assertSet('error', VoucherAttempts::PUBLIC_INVALID_MESSAGE);
    expect(SelfOrder::query()->count())->toBe(0);

    // Kode sah tetap dipakai
    $page->set('voucherCode', 'hemat5')->assertSet('checkedVoucher', 'HEMAT5');
    expect($page->instance()->quote['totals']->voucherDiscount)->toBe('5000.00');
    $page->call('openPay')->call('choosePay', 'cashier')->assertSet('payError', null);
    expect(SelfOrder::query()->sole()->voucher_code)->toBe('HEMAT5');
});

it('pesan dari meja: kode baru ke-11 dalam semenit dari IP yang sama ditolak tanpa dicek, termasuk kode sah', function () {
    $page = guessingOrderPage($this);

    for ($i = 1; $i <= VoucherAttempts::PUBLIC_PER_IP_PER_MINUTE; $i++) {
        $page->set('voucherCode', 'TEBAK'.$i);
    }

    // Kode yang sama diulang (blur lagi) tidak dihitung
    $page->set('voucherCode', 'TEBAK'.VoucherAttempts::PUBLIC_PER_IP_PER_MINUTE)->assertSet('voucherBlocked', null);

    $page->set('voucherCode', 'HEMAT5')->assertSet('checkedVoucher', '');
    expect($page->instance()->quote['error'])->toStartWith('Terlalu banyak mencoba kode voucher');

    $page->call('openPay');
    expect($page->get('error'))->toStartWith('Terlalu banyak mencoba kode voucher')
        ->and(SelfOrder::query()->count())->toBe(0);

    // Mengosongkan kode membuka kembali pesanan tanpa voucher
    $page->set('voucherCode', '')->assertSet('voucherBlocked', null)->call('openPay')->call('choosePay', 'cashier')->assertSet('payError', null);
    expect(SelfOrder::query()->sole()->voucher_code)->toBeNull();
});

it('pesan dari meja: kode yang dicek tidak bisa diisi langsung dari client', function () {
    expect(fn () => guessingOrderPage($this)->set('checkedVoucher', 'HEMAT5'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => guessingOrderPage($this)->set('voucherBlocked', null))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('batas per meja berlaku untuk semua HP di meja itu', function () {
    for ($i = 0; $i < VoucherAttempts::PUBLIC_PER_TABLE_PER_MINUTE; $i++) {
        // Tiap percobaan dari IP berbeda (batas per IP tidak tercapai)
        VoucherAttempts::hitPublic('10.0.0.'.$i, $this->table->id);
    }

    expect(fn () => VoucherAttempts::hitPublic('10.9.9.9', $this->table->id))
        ->toThrow(BusinessException::class, 'Terlalu banyak mencoba kode voucher');

    // Meja lain tidak ikut terkunci
    VoucherAttempts::hitPublic('10.9.9.9', 'meja-lain');
});

it('kasir web: kode voucher dibatasi per user dan kode terpakai tidak bisa diisi langsung', function () {
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '100000.00']);

    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id);

    expect(fn () => $page->set('appliedVoucherCode', 'HEMAT5'))->toThrow(CannotUpdateLockedPropertyException::class);

    for ($i = 1; $i <= VoucherAttempts::STAFF_PER_MINUTE; $i++) {
        $page->set('voucherInput', 'TEBAK'.$i)->call('applyVoucher');
    }

    $page->set('voucherInput', 'HEMAT5')->call('applyVoucher')->assertSet('appliedVoucherCode', null);
    expect($page->get('voucherError'))->toStartWith('Terlalu banyak mencoba kode voucher');
});
