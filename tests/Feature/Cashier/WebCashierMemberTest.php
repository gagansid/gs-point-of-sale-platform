<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Member;
use App\Models\Order;
use App\Models\Shift;
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
        ->assertSee('+ Member')
        ->set('memberSearch', '0812 3456 7890')
        ->assertSet('memberResults.0.name', 'Rina Kusuma')
        ->assertSet('memberResults.0.phone', '•••• 7890')
        ->call('selectMember', $this->member->id)
        ->assertSet('memberName', 'Rina Kusuma')
        ->assertSee('★ Rina Kusuma')
        ->call('addProduct', $this->pos->croissant->id);
    memberPayCash($page, $this);

    expect(Order::query()->sole()->member_name)->toBe('Rina Kusuma');
    $page->assertSet('memberId', null);
});

it('daftar cepat: wajib persetujuan; setelah daftar langsung terpasang', function () {
    Livewire::test(PointOfSale::class)
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

    Livewire::test(PointOfSale::class)->assertDontSee('+ Member');
});
