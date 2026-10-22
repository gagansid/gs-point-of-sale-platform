<?php

declare(strict_types=1);

use App\Actions\Product\SetProductAvailability;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/**
 * Dialog bayar kasir web: Selesaikan hanya bila nominal menutup tagihan dan uang diterima tunai sudah
 * diisi; saran nominal tunai; struk tanpa desimal.
 */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '100000.00']);
});

it('tunai: tanpa uang diterima belum bisa diselesaikan (tidak ada tulisan Lunas, tombol nonaktif, pay ditolak)', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->assertSet('payments.0.tendered', '');

    expect($page->instance()->paymentState())->toMatchArray(['ready' => false, 'message' => 'Masukkan uang diterima']);
    $page->assertSee('Masukkan uang diterima')->assertDontSee('Lunas')
        ->assertSeeHtml('wire:target="pay" disabled');

    $page->call('pay')->assertSet('payError', 'Masukkan uang diterima');
    expect(Order::query()->count())->toBe(0);
});

it('tunai: uang diterima kurang ditolak; cukup → kembalian & selesai', function () {
    // Croissant 24.000 + service 5% + pajak 11% = 27.972 → dibulatkan ke atas 28.000
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->assertSet('payments.0.amount', '28000.00')
        ->set('payments.0.tendered', '20000');

    expect($page->instance()->paymentState())->toMatchArray(['ready' => false, 'tone' => 'danger', 'message' => 'Uang diterima kurang Rp8.000']);
    $page->call('pay')->assertSet('payError', 'Uang diterima kurang Rp8.000');

    $page->set('payments.0.tendered', '50000');
    expect($page->instance()->paymentState())->toMatchArray(['ready' => true, 'change' => '22000.00']);
    $page->assertSee('Kembalian')->assertSee('Rp22.000')
        ->call('pay')->assertSet('payError', null)->assertSet('completedChange', '22000.00');

    expect(Order::query()->count())->toBe(1);
});

it('dialog sukses: ringkasan nomor, total, uang diterima tunai & kembalian; transaksi baru mengosongkan', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->set('payments.0.tendered', '50000')->call('pay')
        ->assertSee('Pembayaran berhasil')->assertSee('Tunai (diterima)')->assertDontSee('Dibayar');

    $summary = $page->get('completedSummary');
    expect($summary)->toMatchArray(['number' => Order::query()->sole()->order_number, 'total' => '28000.00', 'change' => '22000.00', 'items' => 1])
        ->and($summary['methods'])->toBe([['name' => 'Tunai (diterima)', 'amount' => '50000.00']]);

    $page->call('newOrder')->assertSet('completedSummary', null)->assertDontSee('Pembayaran berhasil');
});

it('non-tunai (QRIS): nominal = tagihan langsung siap tanpa uang diterima', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->call('setMethod', 0, $this->pos->methods['qris']->id);

    expect($page->instance()->paymentState())->toMatchArray(['ready' => true, 'message' => 'Lunas']);
    $page->call('pay')->assertSet('payError', null);
    expect(Order::query()->sole()->paid_total)->toBe('28000.00');
});

it('split: sisa belum tertutup belum siap; kembali ke satu metode = nominal penuh lagi', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->call('setMethod', 0, $this->pos->methods['qris']->id)
        ->call('addPaymentRow')
        ->set('payments.0.amount', '10000');

    expect($page->instance()->paymentState())->toMatchArray(['ready' => false, 'tone' => 'warning', 'message' => 'Sisa Rp18.000']);

    $page->call('removePaymentRow', 1)->assertSet('payments.0.amount', '28000.00');
    expect($page->instance()->paymentState()['ready'])->toBeTrue();
});

it('saran nominal tunai: kelipatan berikutnya di atas tagihan, unik & urut', function () {
    expect(PointOfSale::cashSuggestions('113100.00'))->toBe(['115000.00', '120000.00', '150000.00', '200000.00'])
        ->and(PointOfSale::cashSuggestions('50000.00'))->toBe(['55000.00', '60000.00', '100000.00'])
        ->and(PointOfSale::cashSuggestions('0.00'))->toBe([])
        ->and(PointOfSale::cashSuggestions('abc'))->toBe([]);
});

it('setTendered: hanya "exact" atau saran server; nilai lain dari browser diabaikan', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->call('setTendered', 0, 'exact')->assertSet('payments.0.tendered', '28000.00')
        ->call('setTendered', 0, '30000.00')->assertSet('payments.0.tendered', '30000.00')
        ->call('setTendered', 0, '999999')->assertSet('payments.0.tendered', '30000.00');
});

it('struk tanpa desimal: service & pajak dibulatkan ke Rupiah, total tetap', function () {
    // 24.050 → service 1.202,50; pajak 11% × 25.252,50 = 2.777,78; total 28.030,28 → 28.100
    $product = TenantContext::run($this->pos->tenantId, fn () => Product::factory()->create(['name' => 'Roti Keju', 'price' => '24050.00']));
    $this->pos->outlet->forceFill(['receipt_show_rounding' => true])->save();

    $page = Livewire::test(PointOfSale::class)->call('addProduct', $product->id)->call('openPayment')
        ->call('setTendered', 0, 'exact')->call('pay')->assertSet('payError', null);
    $order = Order::query()->findOrFail($page->get('completedOrderId'));

    expect($order->tax_total)->toBe('2777.78')->and($order->grand_total)->toBe('28100.00');

    $this->get(route('cashier.orders.receipt', $order))->assertOk()
        ->assertSee('Rp1.203')->assertSee('Rp2.778')->assertSee('Rp28.100')
        // Pembulatan tampil = 28.100 − bulat(28.030,28) = 70
        ->assertSee('Rp70')
        ->assertDontSee('2.777,78')->assertDontSee('1.202,50');
});

it('Kunci & Keluar memakai dialog konfirmasi; produk habis tampil dengan badge', function () {
    TenantContext::run($this->pos->tenantId, fn () => app(SetProductAvailability::class)
        ->handle($this->pos->croissant, $this->pos->outlet, false));

    $this->get(route('cashier.home'))->assertOk()
        ->assertSee('data-confirm-dialog', false)
        ->assertSee('data-confirm-title="Kunci layar?"', false)
        ->assertSee('data-confirm-title="Keluar dari kasir web?"', false);

    Livewire::test(PointOfSale::class)->assertSeeHtml('<span class="pos-tile-soldout">Habis</span>')->assertSee('Stok habis');
});
