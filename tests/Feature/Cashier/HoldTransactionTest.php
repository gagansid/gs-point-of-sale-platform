<?php

declare(strict_types=1);

use App\Actions\Cashier\RegisterWebDevice;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Order;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Tahan transaksi (park) & kartu metode bayar di kasir web. */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create();
});

it('tahan: keranjang kosong untuk pelanggan berikutnya; belum jadi order & stok tidak berubah', function () {
    $stockBefore = stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty;

    $page = Livewire::test(PointOfSale::class)
        ->call('addProduct', $this->pos->croissant->id)
        ->set('holdLabel', 'Baju merah')
        ->call('hold')
        ->assertSet('cart', [])
        ->assertSet('holdLabel', '')
        ->assertSee('Baju merah')
        ->assertDispatched('cashier-toast', message: 'Transaksi Baju merah ditahan', tone: 'success')
        // Total keranjang yang ditahan tidak tertinggal di layar
        ->assertDontSee('Bayar Rp');

    $held = $page->instance()->heldCarts();
    expect($held)->toHaveCount(1)
        ->and($held[0]['label'])->toBe('Baju merah')
        ->and($held[0]['items'])->toBe(1)
        ->and(Order::query()->count())->toBe(0)
        ->and(stockOf($this->pos->croissant, $this->pos->outlet->id)->stock_qty)->toBe($stockBefore);
});

it('layani pelanggan kedua lalu lanjutkan yang pertama; ID order ikut kembali', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id);
    $firstOrderId = $page->get('orderId');
    $page->call('hold');

    // Pelanggan kedua dibayar dulu
    $page->call('addProduct', $this->pos->croissant->id)->call('openPayment')->call('setTendered', 0, 'exact')->call('pay')->assertSet('payError', null);
    expect(Order::query()->count())->toBe(1);

    $page->call('newOrder')->call('resume', $page->instance()->heldCarts()[0]['id'])
        ->assertSet('cart.0.product_id', $this->pos->croissant->id)
        ->assertSet('orderId', $firstOrderId);
    expect($page->instance()->heldCarts())->toBe([]);

    $page->call('openPayment')->call('setTendered', 0, 'exact')->call('pay')->assertSet('payError', null);
    expect(Order::query()->find($firstOrderId))->not->toBeNull();
});

it('lanjutkan saat keranjang terisi = bertukar (yang aktif ikut ditahan)', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('hold');
    $heldId = $page->instance()->heldCarts()[0]['id'];

    $page->call('addProduct', $this->pos->coffee->id)->set("pickOptions.{$this->pos->size->id}", $this->pos->regular->id)->call('savePick')
        ->call('resume', $heldId)
        ->assertSet('cart.0.product_id', $this->pos->croissant->id);

    $held = $page->instance()->heldCarts();
    expect($held)->toHaveCount(1)->and($held[0]['cart'][0]['product_id'])->toBe($this->pos->coffee->id);
});

it('buang keranjang ditahan; maks. 10; keranjang kosong / open bill tidak bisa ditahan', function () {
    $page = Livewire::test(PointOfSale::class);
    $page->call('hold');
    expect($page->instance()->heldCarts())->toBe([]);

    foreach (range(1, PointOfSale::MAX_HELD) as $i) {
        $page->call('addProduct', $this->pos->croissant->id)->call('hold');
    }
    $page->call('addProduct', $this->pos->croissant->id)->call('hold')
        ->assertDispatched('cashier-toast', tone: 'warning')
        ->assertSet('cart.0.product_id', $this->pos->croissant->id);
    expect($page->instance()->heldCarts())->toHaveCount(PointOfSale::MAX_HELD);

    $first = $page->instance()->heldCarts()[0];
    $page->call('discardHeld', $first['id'])
        ->assertDispatched('cashier-toast', message: "Transaksi {$first['label']} dihapus", tone: 'success');
    expect($page->instance()->heldCarts())->toHaveCount(PointOfSale::MAX_HELD - 1);
});

it('keranjang ditahan per browser (device), tidak terlihat di browser lain', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('hold');

    $other = TenantContext::run($this->pos->tenantId, fn () => app(RegisterWebDevice::class)
        ->handle($this->pos->supervisor, $this->pos->outlet->fresh(), (string) Str::uuid()));
    WebCashier::setDevice($other);

    expect(Livewire::test(PointOfSale::class)->instance()->heldCarts())->toBe([]);
});

it('kartu metode bayar: hanya metode aktif di outlet yang bisa dipilih', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment')
        ->call('setMethod', 0, $this->pos->methods['qris']->id)
        ->assertSet('payments.0.payment_method_id', $this->pos->methods['qris']->id)
        ->call('setMethod', 0, uuid())
        ->assertSet('payments.0.payment_method_id', $this->pos->methods['qris']->id)
        ->assertSeeHtml('role="radiogroup"');

    $foreign = posSetup();
    TenantContext::set($this->pos->tenantId);
    $page->call('setMethod', 0, $foreign->methods['cash']->id)
        ->assertSet('payments.0.payment_method_id', $this->pos->methods['qris']->id);
});

it('kasir web tidak memakai dialog confirm() browser (bisa diblokir browser tertanam)', function () {
    $views = collect(File::allFiles(resource_path('views/cashier')))
        ->merge(File::allFiles(resource_path('views/livewire/cashier')))
        ->push(new SplFileInfo(resource_path('views/components/cashier/layout.blade.php')));

    foreach ($views as $file) {
        $content = (string) file_get_contents($file->getPathname());
        expect($content)->not->toContain('wire:confirm', $file->getFilename())
            ->and($content)->not->toMatch('/\bconfirm\(/', $file->getFilename());
    }
});

it('konfirmasi buang order tertahan ada di halaman', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('hold')
        ->assertSeeHtml('class="pos-held-x"')
        ->assertSee('Buang');
});
