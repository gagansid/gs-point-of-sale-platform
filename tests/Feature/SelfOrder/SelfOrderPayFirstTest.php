<?php

declare(strict_types=1);

use App\Actions\Member\SaveVoucher;
use App\Actions\Member\UpdateMemberProgram;
use App\Actions\Payment\StartTableQrisPayment;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Actions\Product\SetProductAvailability;
use App\Actions\SelfOrder\CheckoutSelfOrder;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\ErrorCode;
use App\Enums\OrderStatus;
use App\Enums\SelfOrderStatus;
use App\Exceptions\BusinessException;
use App\Livewire\Cashier\SelfOrders;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\Device;
use App\Models\GatewayTransaction;
use App\Models\Member;
use App\Models\Order;
use App\Models\SelfOrder;
use App\Models\Shift;
use App\Models\ShiftOperator;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use App\Support\WebCashier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** Bayar dulu tanpa terima kasir: checkout server, QRIS di halaman, bayar di kasir, poin (ADR 0027 K2–K3, SPEC Q137 T4). */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 05:00:00');
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'payment_timeout_minutes' => 15]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 2']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
    RateLimiter::clear('qris-ip:127.0.0.1');
    app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz']);

    $this->charges = 0;
    $this->midtransStatus = null;
    Http::fake(function ($request) {
        if (str_ends_with($request->url(), '/v2/charge')) {
            $this->charges++;

            return Http::response(['status_code' => '201', 'transaction_id' => 'mt-'.$this->charges, 'qr_string' => 'QRIS-'.$this->charges, 'expiry_time' => '2026-10-08 12:15:00'], 200);
        }

        return match (true) {
            str_ends_with($request->url(), '/cancel') => Http::response([], 200),
            str_ends_with($request->url(), '/status') && $this->midtransStatus !== null => Http::response($this->midtransStatus, 200),
            default => Http::response([], 404),
        };
    });
});

afterEach(fn () => Carbon::setTestNow());

function payPage(object $test): Testable
{
    SelfOrderContext::set(SelfOrderContext::resolve($test->table->qr_token));

    return Livewire::test(OrderPage::class);
}

/** @return array{self_order: SelfOrder, order: Order, replayed: bool} */
function tableCheckout(object $test, array $overrides = [], ?string $ip = '127.0.0.1', ?Member $member = null): array
{
    return app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($test->table->qr_token), [
        'id' => uuid(), 'customer_name' => 'Andi', 'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]], ...$overrides,
    ], $member, (string) $ip);
}

it('checkout: tagihan langsung awaiting_payment di shift terbuka paling awal, atas nama operator aktif; stok belum dipotong', function () {
    // Shift kedua (lebih baru) di perangkat lain tidak dipakai; operator aktif shift pertama = supervisor
    $device2 = Device::factory()->forOutlet($this->pos->outlet)->create(['device_uid' => 'kasir-2']);
    Carbon::setTestNow('2026-10-08 05:01:00');
    Shift::factory()->forDevice($device2, $this->pos->cashier)->create(['opening_cash' => '0']);
    ShiftOperator::query()->create(['shift_id' => $this->pos->shift->id, 'user_id' => $this->pos->supervisor->id, 'started_at' => now()]);

    ['self_order' => $selfOrder, 'order' => $order, 'replayed' => $replayed] = tableCheckout($this);

    expect($replayed)->toBeFalse()
        ->and($order->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($order->shift_id)->toBe($this->pos->shift->id)
        ->and($order->user_id)->toBe($this->pos->supervisor->id)
        ->and($order->device_id)->toBe($this->pos->device->id)
        ->and($order->grand_total)->toBe('28000.00')
        ->and($order->table_label)->toBe('Meja 2')
        ->and($selfOrder->status)->toBe(SelfOrderStatus::Accepted)->and($selfOrder->order_id)->toBe($order->id)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);
});

it('checkout idempotent: id sama = tagihan sama; id dipakai meja lain ditolak', function () {
    $id = uuid();
    $first = tableCheckout($this, ['id' => $id]);
    $again = tableCheckout($this, ['id' => $id]);

    expect($again['replayed'])->toBeTrue()->and($again['order']->id)->toBe($first['order']->id)
        ->and(Order::query()->count())->toBe(1)->and(SelfOrder::query()->count())->toBe(1);

    $other = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 9']);
    expect(fn () => app(CheckoutSelfOrder::class)->handle(SelfOrderContext::resolve($other->qr_token), [
        'id' => $id, 'customer_name' => 'X', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
    ], null, '127.0.0.1'))->toThrow(BusinessException::class, 'ID pesanan sudah dipakai');
});

it('checkout menghitung ulang harga & ketersediaan; tanpa shift terbuka ditolak; validasi nama tamu', function () {
    $this->pos->croissant->update(['price' => '30000.00']);
    expect(tableCheckout($this)['order']->subtotal)->toBe('30000.00');

    app(SetProductAvailability::class)->handle($this->pos->croissant, $this->pos->outlet, false);
    expect(fn () => tableCheckout($this))->toThrow(BusinessException::class);
    app(SetProductAvailability::class)->handle($this->pos->croissant, $this->pos->outlet, true);

    expect(fn () => tableCheckout($this, ['customer_name' => '']))->toThrow(ValidationException::class);

    $this->pos->shift->forceFill(['open_device_key' => null, 'status' => 'closed', 'closed_at' => now()])->save();
    expect(fn () => tableCheckout($this))->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::SelfOrderDisabled->value)
        ->and($e->getMessage())->toBe(CheckoutSelfOrder::CLOSED_MESSAGE));
});

it('batas: 3 tagihan menunggu bayar per meja, 10 pesanan/jam per IP', function () {
    for ($i = 0; $i < CheckoutSelfOrder::MAX_AWAITING_PER_TABLE; $i++) {
        tableCheckout($this, ip: '10.0.0.'.$i);
    }
    expect(fn () => tableCheckout($this, ip: '10.0.0.9'))->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::TooManyRequests->value));

    Order::query()->update(['status' => OrderStatus::Expired]);
    RateLimiter::increment('self-order-ip:10.1.1.1', 3600, 10);
    expect(fn () => tableCheckout($this, ip: '10.1.1.1'))->toThrow(BusinessException::class, 'Terlalu banyak pesanan dari perangkat ini');
});

it('voucher menutup seluruh tagihan: langsung lunas tanpa pembayaran (member)', function () {
    app(UpdateMemberProgram::class)->handle(true, [$this->pos->outlet->id]);
    app(SaveVoucher::class)->handle(null, ['code' => 'GRATIS', 'name' => 'Gratis', 'type' => 'fixed', 'value' => '100000']);
    $member = Member::factory()->create(['name' => 'Rina']);

    $order = tableCheckout($this, ['voucher_code' => 'GRATIS'], member: $member)['order'];
    expect($order->refresh()->status)->toBe(OrderStatus::Completed)->and($order->grand_total)->toBe('0.00')
        ->and($order->member_id)->toBe($member->id)->and(SelfOrder::query()->sole()->customer_name)->toBe('Rina');
});

it('QRIS: dibuat sekali (klik ganda = QR sama), buat ulang membatalkan QR lama, nominal & masa berlaku dari server', function () {
    $order = tableCheckout($this)['order'];
    $qris = app(StartTableQrisPayment::class);

    $a = $qris->handle($order, '127.0.0.1');
    $b = $qris->handle($order, '127.0.0.1');
    expect($b->id)->toBe($a->id)->and($this->charges)->toBe(1)
        ->and($a->amount)->toBe('28000.00')->and($a->qr_string)->toBe('QRIS-1')
        ->and($a->expires_at->toIso8601String())->toBe('2026-10-08T05:15:00+00:00');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/charge') && $r['custom_expiry']['expiry_duration'] === 15 && $r['transaction_details']['order_id'] === $a->id);

    $c = $qris->handle($order, '127.0.0.1', renew: true);
    expect($c->id)->not->toBe($a->id)->and($a->refresh()->status)->toBe('cancel')
        ->and(GatewayTransaction::query()->where('order_id', $order->id)->where('status', GatewayTransaction::PENDING)->count())->toBe(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/'.$a->id.'/cancel'));

    // Masa berlaku QR tidak melewati batas waktu bayar order (12 menit tersisa)
    Carbon::setTestNow('2026-10-08 05:03:00');
    $qris->handle($order, '127.0.0.1', renew: true);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/charge') && $r['custom_expiry']['expiry_duration'] === 12);
});

it('QRIS: batas 5 QR/10 menit per pesanan; order lunas/kedaluwarsa ditolak; tidak tersedia bila dimatikan owner/outlet', function () {
    $order = tableCheckout($this)['order'];
    $qris = app(StartTableQrisPayment::class);
    for ($i = 0; $i < StartTableQrisPayment::PER_ORDER; $i++) {
        $qris->handle($order, '10.2.2.'.$i, renew: true);
    }
    expect(fn () => $qris->handle($order, '10.2.2.9', renew: true))
        ->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::TooManyRequests->value));

    $order->forceFill(['status' => OrderStatus::Expired])->save();
    expect(fn () => $qris->handle($order, '10.3.3.3'))->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::OrderExpired->value));

    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'allow_qris' => false]);
    $fresh = tableCheckout($this, ip: '10.4.4.4')['order'];
    expect(fn () => $qris->handle($fresh, '10.4.4.4'))->toThrow(fn (BusinessException $e) => expect($e->errorCode)->toBe(ErrorCode::PaymentGatewayNotConfigured->value));
});

it('HP: Bayar → QRIS tampil di halaman; webhook lunas → polling memindahkan ke Pesananku', function () {
    $page = payPage($this)->call('quickAdd', $this->pos->croissant->id)->call('show', 'cart')
        ->set('customerName', 'Andi')
        ->call('openPay')
        ->assertSee('QRIS')->assertSee('Bayar di kasir')->assertSee('E-wallet / virtual account')
        ->call('choosePay', 'qris')
        ->assertSet('view', 'pay')->assertSet('payMethod', 'qris')
        ->assertSee('Rp28.000')->assertSee('Simpan QR')->assertSeeHtml('<svg');

    $order = Order::query()->sole();
    $txn = GatewayTransaction::query()->sole();
    $this->midtransStatus = ['transaction_status' => 'settlement', 'fraud_status' => 'accept', 'status_code' => '200', 'gross_amount' => '28000.00', 'transaction_id' => 'mt-1', 'payment_type' => 'qris'];
    TenantContext::forget();
    $this->postJson("/webhooks/midtrans/{$this->pos->tenantId}", [
        'order_id' => $txn->id, 'status_code' => '200', 'gross_amount' => '28000.00', 'transaction_status' => 'settlement',
        'signature_key' => MidtransClient::signature($txn->id, '200', '28000.00', 'SB-Mid-server-abc'),
    ])->assertOk();
    TenantContext::set($this->pos->tenantId);

    $page->call('checkPayment')->assertSet('view', 'status')->assertDispatched('so-toast')->assertSee('Lunas');
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});

it('HP: QR kedaluwarsa → Buat QR baru / Bayar di kasir; layar kasir menampilkan nomor pesanan & batas waktu', function () {
    $page = payPage($this)->call('quickAdd', $this->pos->croissant->id)->call('show', 'cart')
        ->set('customerName', 'Andi')->call('openPay')->call('choosePay', 'qris');

    Carbon::setTestNow('2026-10-08 05:15:30');
    $page->call('$refresh')->assertSee('QR sudah kedaluwarsa')->assertSee('Buat QR baru')
        ->call('payAtCashier')->assertSet('payMethod', 'cashier')
        ->assertSee('Tunjukkan ke kasir')->assertSee(Order::query()->sole()->order_number)->assertSee('12.15');
});

it('HP: metode tersembunyi sesuai setelan; poin hanya untuk member dengan saldo', function () {
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'allow_pay_at_cashier' => false, 'allow_qris' => false]);
    app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'enabled_payments' => ['qris']]);

    // Tidak ada metode online & kasir dimatikan: bayar di kasir tetap muncul agar pesanan bisa dibayar
    payPage($this)->call('quickAdd', $this->pos->croissant->id)->call('show', 'cart')->set('customerName', 'A')->call('openPay')
        ->assertDontSee('Scan dari e-wallet')->assertDontSee('E-wallet / virtual account')->assertDontSee('Poin member')
        ->assertSee('Bayar di kasir')
        ->call('choosePay', 'qris')->assertSet('payError', 'Metode bayar ini sedang tidak tersedia');
});

it('HP: total berubah sejak keranjang dibuka → ditunjukkan di sheet bayar', function () {
    $page = payPage($this)->call('quickAdd', $this->pos->croissant->id)->call('show', 'cart')->assertSee('Rp28.000');
    $this->pos->croissant->update(['price' => '30000.00']);

    $page->call('openPay')->assertSet('totalChangedFrom', '28000.00')->assertSee('Total berubah dari Rp28.000');
});

it('HP: pesanan sesi lain / meja lain tidak bisa dibuka untuk dibayar; isi pilihan bayar terkunci', function () {
    $foreign = tableCheckout($this)['self_order'];

    payPage($this)->call('openPay', $foreign->id)->assertSet('payOpen', false)->assertSet('payOrderId', null);
    expect(fn () => payPage($this)->set('payOrderId', $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(fn () => payPage($this)->set('view', 'pay'))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('kasir web: tagihan dari HP langsung tampil di Belum dibayar, tanpa pesanan masuk untuk diterima', function () {
    tableCheckout($this);
    $device = webCashierLogin($this, $this->pos, $this->pos->cashier);
    Shift::factory()->forDevice($device, $this->pos->cashier)->create(['opening_cash' => '0']);
    CurrentOutlet::set($this->pos->cashier, $this->pos->outlet->id);
    WebCashier::setDevice($device);
    Auth::shouldUse(WebCashier::GUARD);

    Livewire::test(SelfOrders::class)->assertSee('Belum dibayar')->assertSee('Meja 2')->assertSee('Belum ada pesanan masuk');
});
