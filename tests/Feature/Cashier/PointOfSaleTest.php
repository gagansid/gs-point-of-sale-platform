<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Product\SetProductAvailability;
use App\Enums\ShiftStatus;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Layar transaksi kasir web (Q54). Konteks yang biasanya diisi SetCashierContext dipasang manual
 * karena Livewire::test tidak menjalankan middleware route.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    actAsWebCashier($this, $this->pos, $this->pos->cashier);
});

function actAsWebCashier(object $test, stdClass $pos, User $user): void
{
    $device = webCashierLogin($test, $pos, $user);
    TenantContext::set($pos->tenantId);
    CurrentOutlet::set($user, $pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);

    if (Shift::query()->where('open_device_key', $device->id)->doesntExist()) {
        $pos->webShift = Shift::factory()->forDevice($device, $user)->create(['opening_cash' => '100000.00']);
    }
}

function cashRow(stdClass $pos, string $amount, ?string $tendered = null): array
{
    return ['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => $amount, 'tendered' => $tendered ?? '', 'reference' => ''];
}

it('produk tanpa opsi: klik dua kali = satu baris qty 2; total dari server', function () {
    $page = Livewire::test(PointOfSale::class)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('addProduct', $this->pos->croissant->id)
        ->assertSet('cart.0.qty', 2)
        ->assertCount('cart', 1);

    // 48.000 + service 5% (2.400) + pajak 11% (5.544) = 55.944 → pembulatan ke atas 100 = 56.000
    expect($page->instance()->quote['totals']?->grandTotal)->toBe('56000.00');
    $page->assertSee('Rp56.000');
});

it('produk dengan opsi wajib: dialog opsi, opsi pertama terpilih, harga opsi ikut', function () {
    $page = Livewire::test(PointOfSale::class)
        ->call('addProduct', $this->pos->coffee->id)
        ->assertSet('pickProductId', $this->pos->coffee->id)
        ->assertSet("pickOptions.{$this->pos->size->id}", $this->pos->regular->id)
        ->set("pickOptions.{$this->pos->size->id}", '')
        ->call('savePick')
        ->assertSet('pickError', 'Pilih 1 Ukuran')
        ->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)
        ->set('pickNotes', 'less ice')
        ->call('savePick')
        ->assertSet('pickProductId', null)
        ->assertSet('cart.0.option_ids', [$this->pos->large->id])
        ->assertSet('cart.0.notes', 'less ice');

    expect($page->instance()->quote['totals']?->lines[0]['line_total'])->toBe('27000.00');
});

function pickCoffee(object $page, stdClass $pos, string $sizeId, array $extra = []): object
{
    $page->call('addProduct', $pos->coffee->id)->set("pickOptions.{$pos->size->id}", $sizeId);

    foreach ($extra as $key => $value) {
        $page->set($key, $value);
    }

    return $page->call('savePick');
}

it('produk dengan opsi sama tanpa catatan & diskon digabung ke satu baris', function () {
    $page = Livewire::test(PointOfSale::class);
    pickCoffee($page, $this->pos, $this->pos->large->id);
    pickCoffee($page, $this->pos, $this->pos->large->id, ['pickQty' => 2]);

    $page->assertCount('cart', 1)->assertSet('cart.0.qty', 3);
});

it('beda opsi, ada catatan, atau ada diskon item → baris baru', function () {
    $page = Livewire::test(PointOfSale::class);
    pickCoffee($page, $this->pos, $this->pos->large->id);
    pickCoffee($page, $this->pos, $this->pos->regular->id);
    pickCoffee($page, $this->pos, $this->pos->large->id, ['pickNotes' => 'less ice']);
    pickCoffee($page, $this->pos, $this->pos->large->id, ['pickDiscount' => '1000']);
    // Catatan sama pun tetap baris terpisah (catatan per pesanan)
    pickCoffee($page, $this->pos, $this->pos->large->id, ['pickNotes' => 'less ice']);

    $page->assertCount('cart', 5)->assertSet('cart.0.qty', 1);
});

it('ubah baris menjadi sama dengan baris lain → digabung', function () {
    $page = Livewire::test(PointOfSale::class);
    pickCoffee($page, $this->pos, $this->pos->large->id);
    pickCoffee($page, $this->pos, $this->pos->regular->id);

    $page->call('editLine', 1)->set("pickOptions.{$this->pos->size->id}", $this->pos->large->id)->call('savePick')
        ->assertCount('cart', 1)
        ->assertSet('cart.0.option_ids', [$this->pos->large->id])
        ->assertSet('cart.0.qty', 2);
});

it('ubah qty baris tanpa mengubah opsi tetap satu baris', function () {
    $page = Livewire::test(PointOfSale::class);
    pickCoffee($page, $this->pos, $this->pos->large->id);

    $page->call('editLine', 0)->set('pickQty', 4)->call('savePick')
        ->assertCount('cart', 1)->assertSet('cart.0.qty', 4);
});

it('bayar tunai: order di device web & shift browser, kembalian, keranjang kosong, ID order baru', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment');
    $orderId = $page->get('orderId');
    $grand = $page->get('payments.0.amount');

    $page->set('payments.0.tendered', '50000')->call('pay')->assertSet('payError', null)->assertSet('cart', []);

    $order = Order::query()->findOrFail($orderId);
    expect($order->device_id)->toBe($this->pos->webDevice->id)
        ->and($order->shift_id)->toBe($this->pos->webShift->id)
        ->and($order->user_id)->toBe($this->pos->cashier->id)
        ->and($order->grand_total)->toBe($grand)
        ->and($page->get('completedOrderId'))->toBe($orderId)
        ->and($page->get('completedChange'))->toBe(bcsub('50000', $grand, 2))
        ->and($page->get('orderId'))->not->toBe($orderId);

    $this->get(route('cashier.orders.receipt', $order))->assertOk()->assertSee('Croissant');
});

it('split tunai + QRIS dengan referensi', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment');
    $grand = $page->get('payments.0.amount');

    $page->set('payments', [
        cashRow($this->pos, '10000', '10000'),
        ['id' => uuid(), 'payment_method_id' => $this->pos->methods['qris']->id, 'amount' => bcsub($grand, '10000', 2), 'tendered' => '', 'reference' => 'QR123'],
    ])->call('pay')->assertSet('payError', null);

    $order = Order::query()->with('payments')->sole();
    expect($order->payments)->toHaveCount(2)
        ->and($order->payments->firstWhere('payment_method_id', $this->pos->methods['qris']->id)?->reference)->toBe('QR123');
});

it('pembayaran kurang / tanpa shift ditolak dengan pesan, tanpa order', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->call('openPayment')->set('payments', [cashRow($this->pos, '1000', '1000')])->call('pay')
        ->assertSet('payError', fn (?string $e): bool => str_starts_with((string) $e, 'Sisa Rp'));

    $this->pos->webShift->forceFill(['status' => ShiftStatus::Closed, 'open_device_key' => null, 'closed_at' => now()])->save();
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->call('openPayment')->call('setTendered', 0, 'exact')->call('pay')
        ->assertSet('payError', fn (?string $e): bool => $e !== null);

    expect(Order::query()->count())->toBe(0);
});

it('diskon di atas batas kasir (10%) ditolak saat bayar', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('discountType', 'percent')->set('discountValue', '50')
        ->call('openPayment')->call('setTendered', 0, 'exact')->call('pay')
        ->assertSet('payError', fn (?string $e): bool => $e !== null);

    expect(Order::query()->count())->toBe(0);
});

it('orderId terkunci: tidak bisa diganti dari browser (idempotency key)', function () {
    Livewire::test(PointOfSale::class)->set('orderId', uuid());
})->throws(CannotUpdateLockedPropertyException::class);

it('produk habis & produk tenant lain tidak bisa masuk keranjang', function () {
    $foreign = posSetup();
    TenantContext::set($this->pos->tenantId);
    TenantContext::run($this->pos->tenantId, fn () => app(SetProductAvailability::class)
        ->handle($this->pos->croissant, $this->pos->outlet, false));

    Livewire::test(PointOfSale::class)
        ->call('addProduct', $this->pos->croissant->id)
        ->call('addProduct', $foreign->coffee->id)
        ->assertSet('cart', [])
        ->assertDispatched('cashier-toast', message: 'Produk tidak tersedia', tone: 'warning');
});

it('toggle habis: kasir ditolak, supervisor boleh', function () {
    Livewire::test(PointOfSale::class)->call('toggleAvailability', $this->pos->croissant->id)->assertForbidden();

    actAsWebCashier($this, $this->pos, $this->pos->supervisor);
    Livewire::test(PointOfSale::class)->call('toggleAvailability', $this->pos->croissant->id);

    expect(Product::query()->atOutlet($this->pos->outlet->id)->findOrFail($this->pos->croissant->id)->is_available)->toBeFalse();
});

it('struk order tenant lain 404', function () {
    $foreign = posSetup();
    $order = TenantContext::run($foreign->tenantId, fn () => app(CheckoutOrder::class)->handle($foreign->cashier, $foreign->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $foreign->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $foreign->methods['cash']->id, 'amount' => 100000]],
    ]))['order']);
    TenantContext::forget();

    $this->get(route('cashier.orders.receipt', $order))->assertNotFound();
});

it('halaman transaksi tampil lewat route lengkap (middleware + Livewire)', function () {
    TenantContext::forget();
    $this->get(route('cashier.home'))->assertOk()->assertSee('Croissant')->assertSeeLivewire(PointOfSale::class);
});
