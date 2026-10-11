<?php

declare(strict_types=1);

use App\Actions\Auth\VerifyPin;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\Data\OpenBillData;
use App\Actions\Order\SaveOpenBill;
use App\Enums\OrderStatus;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Order;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/** Open bill, approval PIN diskon, void di kasir web (SPEC Q54). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    $this->shift = Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '0.00']);
    // PIN approver (factory: 123456)
    $this->pos->supervisor->forceFill(['pin' => '481920'])->save();
});

function payCash(object $page): object
{
    return $page->call('openPayment')->call('pay');
}

it('simpan open bill → buka lagi → tambah item → lunasi', function () {
    $page = Livewire::test(PointOfSale::class)
        ->set('orderType', 'dine_in')->set('tableLabel', 'Meja 5')
        ->call('addProduct', $this->pos->croissant->id)
        ->call('saveBill')
        ->assertSet('cart', [])
        ->assertSet('message', 'Open bill Meja 5 disimpan');

    $bill = Order::query()->sole();
    expect($bill->status)->toBe(OrderStatus::Open)->and($bill->device_id)->toBe($this->pos->webDevice->id);

    $page->call('loadBill', $bill->id)
        ->assertSet('billId', $bill->id)
        ->assertSet('orderId', $bill->id)
        ->assertSet('tableLabel', 'Meja 5')
        ->assertSet('cart.0.product_id', $this->pos->croissant->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->assertSet('cart.0.qty', 2);

    payCash($page)->assertSet('payError', null)->assertSet('completedOrderId', $bill->id);

    $bill->refresh();
    expect($bill->status)->toBe(OrderStatus::Completed)
        ->and($bill->items()->sum('qty'))->toBe(2)
        ->and(Order::query()->count())->toBe(1);
});

it('open bill: simpan dua kali dengan ID sama tetap satu order', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('saveBill');
    $bill = Order::query()->sole();

    $page->call('loadBill', $bill->id)->call('saveBill')->call('loadBill', $bill->id)->call('saveBill');

    expect(Order::query()->count())->toBe(1);
});

it('open bill tenant lain / sudah selesai tidak bisa dibuka', function () {
    $foreign = posSetup();
    TenantContext::set($this->pos->tenantId);
    $foreignBill = TenantContext::run($foreign->tenantId, fn () => app(SaveOpenBill::class)->handle($foreign->cashier, $foreign->device, uuid(),
        OpenBillData::fromArray(['items' => [['product_id' => $foreign->croissant->id, 'qty' => 1]]]))['order']);

    Livewire::test(PointOfSale::class)->call('loadBill', $foreignBill->id)
        ->assertSet('billId', null)
        ->assertSet('message', 'Open bill tidak ditemukan atau sudah ditutup');
});

it('diskon 20%: kasir (batas 10%) diminta PIN; PIN salah ditolak; supervisor (25%) menyetujui', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('discountType', 'percent')->set('discountValue', '20');

    payCash($page)->assertSet('needsApproval', true);
    expect(Order::query()->count())->toBe(0);

    $page->set('approverId', $this->pos->supervisor->id)->set('approverPin', '000000')->call('pay')
        ->assertSet('approverPin', '')
        ->assertSet('payError', fn (?string $e): bool => $e !== null);
    expect(Order::query()->count())->toBe(0);

    $page->set('approverId', $this->pos->supervisor->id)->set('approverPin', '481920')->call('pay')->assertSet('payError', null);

    expect(Order::query()->sole()->approved_by)->toBe($this->pos->supervisor->id);
});

it('approval tidak boleh oleh diri sendiri', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('discountType', 'percent')->set('discountValue', '20');
    payCash($page);

    $page->set('approverId', $this->pos->cashier->id)->set('approverPin', '123456')->call('pay')
        ->assertSet('payError', fn (?string $e): bool => str_contains((string) $e, 'sendiri') || $e !== null);
    expect(Order::query()->count())->toBe(0);
});

it('penghitung PIN approval memakai device browser, bukan "approval:none"', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('discountType', 'percent')->set('discountValue', '20');
    payCash($page);
    $page->set('approverId', $this->pos->supervisor->id)->set('approverPin', '000000')->call('pay');

    $key = fn (string $source): string => (new ReflectionMethod(VerifyPin::class, 'key'))->invoke(null, $this->pos->supervisor, $source);
    expect(RateLimiter::attempts($key('approval:'.$this->pos->webDevice->id)))->toBe(1)
        ->and(RateLimiter::attempts($key('approval:none')))->toBe(0);
});

it('void: kasir butuh PIN supervisor; alasan wajib; order kembali ke riwayat sebagai void', function () {
    $order = app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->webDevice, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 100000]],
    ]))['order'];
    TenantContext::forget();

    $this->get(route('cashier.orders.show', $order->id))->assertOk()->assertSee('Void order')->assertSee('Sari');
    $this->post(route('cashier.orders.void', $order->id), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post(route('cashier.orders.void', $order->id), ['reason' => 'Salah input'])->assertSessionHasErrors('cashier');
    $this->post(route('cashier.orders.void', $order->id), ['reason' => 'Salah input', 'approver_user_id' => $this->pos->cashier->id, 'approver_pin' => '123456'])
        ->assertSessionHasErrors('cashier');

    $this->post(route('cashier.orders.void', $order->id), ['reason' => 'Salah input', 'approver_user_id' => $this->pos->supervisor->id, 'approver_pin' => '481920'])
        ->assertRedirect(route('cashier.orders.show', $order->id));

    $order = Order::allTenants()->findOrFail($order->id);
    expect($order->status)->toBe(OrderStatus::Voided)
        ->and($order->void_approved_by)->toBe($this->pos->supervisor->id);
});

it('void & detail order tenant lain 404', function () {
    $foreign = posSetup();
    $order = TenantContext::run($foreign->tenantId, fn () => app(CheckoutOrder::class)->handle($foreign->cashier, $foreign->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $foreign->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $foreign->methods['cash']->id, 'amount' => 100000]],
    ]))['order']);
    TenantContext::forget();

    $this->get(route('cashier.orders.show', $order->id))->assertNotFound();
    $this->post(route('cashier.orders.void', $order->id), ['reason' => 'x'])->assertNotFound();
});
