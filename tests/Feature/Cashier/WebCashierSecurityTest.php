<?php

declare(strict_types=1);

use App\Actions\Auth\VerifyPin;
use App\Actions\Cashier\RegisterWebDevice;
use App\Exceptions\BusinessException;
use App\Livewire\Cashier\PointOfSale;
use App\Models\Device;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Temuan audit keamanan kasir web (2026-10-11). */
beforeEach(function () {
    $this->pos = posSetup(openShift: false);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create();
    $this->pos->supervisor->forceFill(['pin' => '481920'])->save();
});

it('simpan bill dengan qty/tipe order hasil manipulasi browser ditolak validasi', function (string $prop, mixed $value) {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set($prop, $value)->call('saveBill')
        ->assertSet('messageTone', 'warning');

    expect(Order::query()->count())->toBe(0);
})->with([
    'qty negatif' => ['cart.0.qty', -5],
    'qty > 999' => ['cart.0.qty', 5000],
    'tipe order asing' => ['orderType', 'drive_thru'],
    'diskon item negatif' => ['cart.0.discount', '-1000.00'],
]);

it('PIN approver tidak tersisa di state setelah bayar gagal validasi', function () {
    Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('needsApproval', true)->set('approverId', $this->pos->supervisor->id)->set('approverPin', '481920')
        ->set('cart.0.qty', -1)
        ->call('openPayment')->call('pay')
        ->assertSet('approverPin', '');
});

it('penghitung PIN approval kasir web per pelaku: browser baru tidak mereset', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)
        ->set('discountType', 'percent')->set('discountValue', '20');
    $page->call('openPayment')->call('pay');
    $page->set('approverId', $this->pos->supervisor->id)->set('approverPin', '000000')->call('pay');

    // "Browser baru" (cookie dihapus): device lain dengan shift sendiri, pelaku sama
    $newDevice = TenantContext::run($this->pos->tenantId, fn () => app(RegisterWebDevice::class)->handle($this->pos->cashier, $this->pos->outlet->fresh(), (string) Str::uuid()));
    Shift::factory()->forDevice($newDevice, $this->pos->cashier)->create();
    WebCashier::setDevice($newDevice);
    $page->set('approverId', $this->pos->supervisor->id)->set('approverPin', '000000')->call('pay');

    $key = (new ReflectionMethod(VerifyPin::class, 'key'))->invoke(null, $this->pos->supervisor, 'approval:web-user:'.$this->pos->cashier->id);
    expect(RateLimiter::attempts($key))->toBe(2);
});

it('batas device kasir web per outlet', function () {
    config(['pos.web_cashier.max_devices_per_outlet' => 2]);
    // Sudah ada 1 dari beforeEach
    TenantContext::run($this->pos->tenantId, fn () => app(RegisterWebDevice::class)->handle($this->pos->cashier, $this->pos->outlet->fresh(), (string) Str::uuid()));

    expect(fn () => TenantContext::run($this->pos->tenantId, fn () => app(RegisterWebDevice::class)->handle($this->pos->cashier, $this->pos->outlet->fresh(), (string) Str::uuid())))
        ->toThrow(BusinessException::class);
    expect(Device::query()->where('platform', 'web')->count())->toBe(2);
});

it('toggle habis hanya produk di katalog outlet (produk nonaktif / outlet lain 404)', function () {
    $inactive = TenantContext::run($this->pos->tenantId, fn () => Product::factory()->create(['name' => 'Arsip', 'is_active' => false]));
    $sari = $this->pos->supervisor;
    $sari->forceFill(['web_cashier_access' => true])->save();
    $this->actingAs($sari, WebCashier::GUARD);
    CurrentOutlet::set($sari, $this->pos->outlet->id);

    Livewire::test(PointOfSale::class)->call('toggleAvailability', $inactive->id)->assertNotFound();
});

it('nominal cepat hanya kata kunci/pecahan yang dikenal', function () {
    $page = Livewire::test(PointOfSale::class)->call('addProduct', $this->pos->croissant->id)->call('openPayment');
    $amount = $page->get('payments.0.amount');

    $page->call('setTendered', 0, '50000')->assertSet('payments.0.tendered', '50000')
        ->call('setTendered', 0, '999999')->assertSet('payments.0.tendered', '50000')
        ->call('setTendered', 0, 'exact')->assertSet('payments.0.tendered', $amount);
});

it('login kasir web: batas 20 gagal per IP lintas username', function () {
    Auth::forgetGuards();
    foreach (range(1, 20) as $i) {
        $this->post(route('cashier.login.store'), ['mode' => 'username', 'business' => 'x', 'login' => "u{$i}", 'password' => 'salah']);
    }

    $this->post(route('cashier.login.store'), ['mode' => 'username', 'business' => 'x', 'login' => 'u99', 'password' => 'salah'])
        ->assertSessionHasErrors('login');
    expect(session('errors')->first('login'))->toContain('Terlalu banyak');
});
