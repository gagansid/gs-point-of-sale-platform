<?php

declare(strict_types=1);

use App\Actions\Member\AdjustMemberPoints;
use App\Actions\Member\SaveMemberTiers;
use App\Actions\Member\SaveStampProgram;
use App\Actions\Member\SaveVoucher;
use App\Actions\Member\SetMemberPin;
use App\Actions\Member\UpdateMemberProgram;
use App\Enums\PaymentCategory;
use App\Enums\VoucherSource;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Member;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Shift;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Member\TierEvaluator;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/** Kasir web: cari, daftar cepat, dan pasang member ke transaksi (ADR 0013, M1). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '100000.00']);
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $this->member = Member::factory()->create(['name' => 'Rina Kusuma', 'phone' => '+6281234567890']);
});

function memberPayCash(object $page, object $test): void
{
    $page->call('openPayment')->set('payments.0.tendered', '50000')->call('pay');
}

it('cari member lalu bayar: order tersimpan dengan member', function () {
    $page = Livewire::test(PointOfSale::class)
        ->assertSee('Member: kode / no. HP')
        ->set('memberSearch', '0812 3456 7890')
        ->assertSet('memberResults.0.name', 'Rina Kusuma')
        ->assertSet('memberResults.0.phone', '•••• 7890')
        ->call('selectMember', $this->member->id)
        ->assertSet('memberName', 'Rina Kusuma')
        ->assertSeeHtml('class="pos-member-chip"')->assertSee('Rina Kusuma')
        ->call('addProduct', $this->pos->croissant->id);
    memberPayCash($page, $this);

    expect(Order::query()->sole()->member_name)->toBe('Rina Kusuma');
    $page->assertSet('memberId', null);
});

it('daftar cepat: wajib persetujuan; setelah daftar langsung terpasang', function () {
    Livewire::test(PointOfSale::class)
        ->set('memberSearch', '0812 9999 0000')
        ->call('openMemberRegistration')
        ->assertSet('showMembers', true)
        ->assertSet('newMemberPhone', '0812 9999 0000')
        ->set('newMemberName', 'Budi')->set('newMemberPhone', '0812 9999 0000')
        ->call('registerMember')
        ->assertSet('memberError', 'Pelanggan harus menyetujui pemakaian data untuk program member')
        ->set('newMemberConsent', true)
        ->call('registerMember')
        ->assertSet('memberName', 'Budi')
        ->assertDispatched('cashier-toast', message: 'Member Budi terdaftar');

    expect(Member::query()->where('phone', '+6281299990000')->value('joined_outlet_id'))->toBe($this->pos->outlet->id);
});

it('member ikut ditahan & dilanjutkan, dan ikut open bill', function () {
    $page = Livewire::test(PointOfSale::class)
        ->call('selectMember', $this->member->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('hold')
        ->assertSet('memberId', null);

    $page->call('resume', $page->instance()->heldCarts()[0]['id'])->assertSet('memberName', 'Rina Kusuma')
        ->set('orderType', 'dine_in')->set('tableLabel', 'Meja 2')
        ->call('saveBill');

    $bill = Order::query()->sole();
    expect($bill->member_id)->toBe($this->member->id);

    $page->call('loadBill', $bill->id)->assertSet('memberName', 'Rina Kusuma')
        ->call('clearMember')->call('saveBill');
    expect($bill->refresh()->member_id)->toBeNull();
});

it('ID member dari browser divalidasi server: member tenant lain / nonaktif ditolak', function () {
    $this->member->forceFill(['is_active' => false])->save();

    Livewire::test(PointOfSale::class)
        ->call('selectMember', $this->member->id)
        ->assertSet('memberId', null)
        ->assertSet('memberError', 'Member tidak ditemukan atau tidak aktif')
        // Manipulasi state langsung → ditolak saat bayar
        ->set('memberId', $this->member->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('openPayment')->call('pay');

    expect(Order::query()->count())->toBe(0);
});

it('program nonaktif: tombol member tidak tampil', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(UpdateMemberProgram::class)->handle(false, []));

    Livewire::test(PointOfSale::class)->assertDontSee('Member: kode / no. HP');
});

it('Enter / scanner: kode atau nomor HP persis langsung dipasang', function (string $term) {
    Livewire::test(PointOfSale::class)
        ->set('memberSearch', $term)
        ->call('pickMember')
        ->assertSet('memberId', $this->member->id)
        ->assertSet('memberSearch', '');
})->with(['kode' => fn () => mb_strtolower($this->member->code), 'nomor HP' => '0812-3456-7890']);

it('nama kembar: hasil dibedakan kode & 4 digit HP; Enter meminta memilih', function () {
    $twin = Member::factory()->create(['name' => 'Rina Kusuma', 'phone' => '+6281200001111']);

    $page = Livewire::test(PointOfSale::class)->set('memberSearch', 'rina kusuma');
    expect(collect($page->get('memberResults'))->pluck('phone')->sort()->values()->all())->toBe(['•••• 1111', '•••• 7890']);

    $page->call('pickMember')
        ->assertSet('memberId', null)
        ->assertSet('memberError', 'Pilih member dari daftar')
        ->assertSee($twin->code)->assertSee($this->member->code);
});

it('metode Poin hanya muncul bila member terpasang; bayar poin dengan PIN member', function () {
    $owner = User::factory()->owner()->create();
    app(AdjustMemberPoints::class)->handle($this->member, 300, 'Saldo awal', $owner);
    app(SetMemberPin::class)->handle($this->member, '4821');
    $points = PaymentMethod::query()->where('category', PaymentCategory::Points)->sole();

    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id);
    expect($page->instance()->paymentMethods->pluck('id'))->not->toContain($points->id);

    $page->call('selectMember', $this->member->id);
    expect($page->instance()->paymentMethods->pluck('id'))->toContain($points->id);

    $page->call('openPayment')
        ->call('setMethod', 0, $points->id)->set('payments.0.amount', '10000')
        ->call('addPaymentRow')->set('payments.1.tendered', '20000')
        ->assertSee('Verifikasi member')->assertDontSee('No. referensi');
    // Bukti member wajib sebelum Selesaikan aktif
    expect($page->instance()->paymentState())->toMatchArray(['ready' => false, 'message' => 'Isi kode QR atau PIN member']);

    $page->set('memberPin', '4821')
        ->call('pay')
        ->assertSet('memberPin', '');

    expect(Order::query()->sole()->points_redeemed)->toBe(100)
        ->and($this->member->refresh()->points_balance)->toBe(300 - 100 + Order::query()->sole()->points_earned);
});

it('tier & kartu stempel: chip menampilkan tier, gratis stempel dipakai lalu ikut ditahan', function () {
    app(SaveMemberTiers::class)->handle([['name' => 'Gold', 'min_spend_12m' => '0', 'point_multiplier' => '1', 'discount_percent' => '0']]);
    app(TierEvaluator::class)->refresh($this->member);
    $program = app(SaveStampProgram::class)->handle(null, ['name' => 'Roti', 'stamps_required' => 2, 'product_ids' => [$this->pos->croissant->id]]);

    // Belum cukup stempel: tombol nonaktif, toggle diabaikan
    $page = Livewire::test(PointOfSale::class)
        ->call('selectMember', $this->member->id)
        ->assertSeeHtml('class="pos-member-chip-tier"')->assertSee('Gold')
        ->assertSee('Roti')
        ->call('toggleStamp', $program->id)
        ->assertSet('stampProgramIds', []);

    // Dapat 2 stempel dari transaksi ini
    $page->call('addProduct', $this->pos->croissant->id)->call('increment', 0)
        ->call('openPayment')->set('payments.0.tendered', '100000')->call('pay')->assertSet('payError', null);
    expect(Order::query()->sole()->stamps_earned)->toBe(2);

    // Transaksi berikutnya: pakai gratis → Croissant 0, total 0 (tanpa pembayaran)
    $page->call('selectMember', $this->member->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('toggleStamp', $program->id)
        ->assertSet('stampProgramIds', [$program->id])
        ->assertSee('Gratis stempel')
        ->call('hold')
        ->assertSet('stampProgramIds', []);

    $held = $page->instance()->heldCarts();
    $page->call('resume', $held[0]['id'])->assertSet('stampProgramIds', [$program->id]);
});

it('voucher: kode salah tampil pesan, kode sah dipakai & tersimpan di order; voucher ulang tahun dari member', function () {
    app(SaveVoucher::class)->handle(null, ['code' => 'HEMAT5', 'name' => 'Hemat', 'type' => 'fixed', 'value' => '5000']);

    $page = Livewire::test(PointOfSale::class)
        ->call('addProduct', $this->pos->croissant->id)
        ->set('voucherInput', 'salah')->call('applyVoucher')
        ->assertSet('voucherError', 'Kode voucher tidak ditemukan')->assertSet('appliedVoucherCode', null)
        ->set('voucherInput', 'hemat5')->call('applyVoucher')
        ->assertSet('appliedVoucherCode', 'HEMAT5')->assertSet('voucherError', null)
        ->assertSee('Voucher HEMAT5');
    memberPayCash($page, $this);

    expect(Order::query()->sole()->voucher_discount)->toBe('5000.00');

    $birthday = new Voucher(['name' => 'Voucher ulang tahun', 'type' => 'fixed', 'value' => '10000', 'quota' => 1, 'member_only' => true]);
    $birthday->forceFill(['source' => VoucherSource::Birthday, 'member_id' => $this->member->id])->save();

    $page->call('selectMember', $this->member->id)
        ->assertSee('Voucher ulang tahun Rp10.000')
        ->call('addProduct', $this->pos->croissant->id)
        ->call('togglePersonalVoucher', $birthday->id)
        ->assertSet('voucherId', $birthday->id);
    memberPayCash($page, $this);

    expect(Order::query()->where('voucher_id', $birthday->id)->sole()->voucher_discount)->toBe('10000.00');
});
