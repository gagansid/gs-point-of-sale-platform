<?php

declare(strict_types=1);

use App\Actions\Member\UpdateMemberProgram;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Livewire\Cashier\PointOfSale;
use App\Livewire\Cashier\SelfOrderBadge;
use App\Livewire\Cashier\SelfOrders;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Member;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\MemberPortal;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Self-order S2: halaman pesan di HP (Livewire) dan menu Pesanan meja di kasir web (ADR 0014). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'welcome_text' => 'Selamat datang di Kopi Senja']);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
});

function orderPage(object $test): Testable
{
    SelfOrderContext::set(SelfOrderContext::resolve($test->table->qr_token));

    return Livewire::test(OrderPage::class);
}

it('HP: halaman QR menampilkan menu; pilih opsi wajib, kirim pesanan, status menunggu kasir', function () {
    $this->get($this->table->orderUrl())->assertOk()->assertSee('Meja 5')->assertSee('Selamat datang di Kopi Senja')->assertSee('Croissant');

    orderPage($this)
        ->call('addProduct', $this->pos->coffee->id)
        ->assertSet('pickProductId', $this->pos->coffee->id)
        ->call('savePick')->assertSet('pickError', 'Pilih 1 Ukuran')
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)
        ->set('pickQty', 2)
        ->call('savePick')
        ->assertSet('cart.0.option_names', ['Large'])
        ->call('show', 'cart')
        ->assertSee('Rp54.000')
        ->call('submit')
        ->assertSet('error', 'Nama wajib diisi')
        ->set('customerName', 'Andi')
        ->call('submit')
        ->assertSet('error', null)
        ->assertSet('view', 'status')
        ->assertSet('cart', [])
        ->assertSee('Menunggu kasir');

    $selfOrder = SelfOrder::query()->sole();
    expect($selfOrder->customer_name)->toBe('Andi')->and($selfOrder->items[0]['qty'])->toBe(2);
});

it('HP: member yang masuk di halaman member otomatis terpasang', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    $member = Member::factory()->create(['name' => 'Rina']);
    $member->forceFill(['password' => 'rahasia123'])->save();
    MemberPortal::guard()->login($member);
    session()->put(MemberPortal::SESSION_PASSWORD, MemberPortal::passwordFingerprint($member->refresh()));

    orderPage($this)
        ->assertSet('customerName', 'Rina')
        ->call('addProduct', $this->pos->croissant->id)->call('savePick')
        ->call('show', 'cart')->assertSee('Masuk sebagai member')
        ->call('submit')->assertSet('view', 'status');

    expect(SelfOrder::query()->sole()->member_id)->toBe($member->id);
});

it('kasir web: menu Pesanan meja → terima & tolak; bayar dari layar Transaksi; item terkunci', function () {
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);

    $context = SelfOrderContext::resolve($this->table->qr_token);
    $submit = fn (string $name) => app(SubmitSelfOrder::class)->handle($context, [
        'id' => uuid(), 'customer_name' => $name, 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], null, '127.0.0.1')['self_order'];
    $a = $submit('Andi');
    $b = $submit('Budi');

    // Tanpa shift: diarahkan buka shift
    $this->get(route('cashier.self-orders'))->assertRedirect(route('cashier.shift'));
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '100000.00']);
    $this->get(route('cashier.self-orders'))->assertOk()->assertSee('Pesanan masuk');

    Livewire::test(SelfOrderBadge::class)->assertSee('2');

    Livewire::test(SelfOrders::class)
        ->assertSee('Andi')->assertSee('Budi')->assertSee('1× Croissant')
        ->call('accept', $a->id)
        ->assertDispatched('cashier-toast')
        ->call('startReject', $b->id)
        ->set('rejectReason', 'Meja salah')
        ->call('reject')
        ->assertSee('Belum dibayar');

    $order = Order::query()->sole();
    expect($order->status)->toBe(OrderStatus::AwaitingPayment)->and($b->refresh()->status)->toBe(SelfOrderStatus::Rejected);

    Livewire::withQueryParams(['bill' => $order->id])->test(PointOfSale::class)
        ->assertSet('billId', $order->id)
        ->assertSet('billLocked', true)
        ->assertSee('Pesanan meja')
        ->call('increment', 0)
        ->assertSet('cart.0.qty', 1)
        ->call('openPayment')->set('payments.0.tendered', '50000')->call('pay')
        ->assertSet('payError', null);

    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});
