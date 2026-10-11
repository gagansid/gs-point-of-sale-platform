<?php

declare(strict_types=1);

use App\Actions\Catalog\GetCatalog;
use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Actions\Payment\StartOnlinePayment;
use App\Actions\Payment\TestPaymentGateway;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Actions\SelfOrder\AcceptSelfOrder;
use App\Actions\SelfOrder\ExpireSelfOrders;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\SubmitSelfOrder;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Exceptions\BusinessException;
use App\Filament\Dashboard\Pages\PaymentGatewaySettings;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\PaymentGatewaySetting;
use App\Models\PaymentMethod;
use App\Models\SelfOrder;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use App\Support\CurrentOutlet;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Self-order S3 (ADR 0014, Q72–Q73): Midtrans milik owner. Croissant 24.000 → 28.000.
 */
beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true]);
    $this->table = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
    app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz']);
    // Satu fake untuk semua panggilan Midtrans; jawaban status diatur per test lewat $this->apiStatus
    $this->apiStatus = ['status_code' => '404'];
    $this->apiCode = 404;
    Http::fake(fn ($request) => match (true) {
        str_contains($request->url(), '/snap/v1/transactions') => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token'], 201),
        str_ends_with($request->url(), '/cancel') => Http::response([], 200),
        str_ends_with($request->url(), '/status') => Http::response($this->apiStatus, $this->apiCode),
        default => Http::response([], 404),
    });
});

afterEach(fn () => Carbon::setTestNow());

function acceptedOrder(object $test): Order
{
    $context = SelfOrderContext::resolve($test->table->qr_token);
    $selfOrder = app(SubmitSelfOrder::class)->handle($context, [
        'id' => uuid(), 'customer_name' => 'Andi', 'items' => [['product_id' => $test->pos->croissant->id, 'qty' => 1]],
    ], null, '127.0.0.1')['self_order'];

    return app(AcceptSelfOrder::class)->handle($test->pos->cashier, $test->pos->device, $selfOrder);
}

/** Kirim notifikasi Midtrans bertanda tangan ke webhook tenant; status API di-fake sesuai $apiStatus. */
function notify(object $test, GatewayTransaction $txn, string $apiStatus = 'settlement', ?string $grossAmount = null, ?string $signatureKey = null, ?string $tenantId = null)
{
    $gross = $grossAmount ?? number_format((float) $txn->amount, 2, '.', '');
    $test->apiCode = 200;
    $test->apiStatus = [
        'order_id' => $txn->id, 'transaction_status' => $apiStatus, 'fraud_status' => 'accept', 'status_code' => '200',
        'gross_amount' => $gross, 'transaction_id' => 'mt-123', 'payment_type' => 'qris',
    ];
    TenantContext::forget();

    $response = $test->postJson(route('webhooks.midtrans', ['tenant' => $tenantId ?? $test->pos->tenantId]), [
        'order_id' => $txn->id, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'settlement',
        'signature_key' => $signatureKey ?? MidtransClient::signature($txn->id, '200', $gross, 'SB-Mid-server-abc'),
    ]);
    TenantContext::set($test->pos->tenantId);

    return $response;
}

it('setelan: key terenkripsi & tidak tampil; aktif butuh key; metode Online dibuat tetapi tidak dipilih kasir', function () {
    $raw = DB::table('payment_gateway_settings')->value('server_key');
    expect($raw)->not->toContain('SB-Mid-server-abc')
        ->and(PaymentGatewaySetting::current()->server_key)->toBe('SB-Mid-server-abc');

    // Key kosong = key lama tetap
    app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'server_key' => '', 'client_key' => null]);
    expect(PaymentGatewaySetting::current()->server_key)->toBe('SB-Mid-server-abc');

    $online = PaymentMethod::query()->where('category', PaymentCategory::Online)->sole();
    expect($online->is_active)->toBeTrue()
        ->and(app(GetCatalog::class)->handle($this->pos->outlet)['payment_methods']->pluck('category')->all())->not->toContain(PaymentCategory::Online);

    // Dicatat manual dari kasir/API → ditolak
    expect(fn () => app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $online->id, 'amount' => 28000]],
    ])))->toThrow(BusinessException::class);

    DB::table('payment_gateway_settings')->update(['server_key' => null]);
    expect(fn () => app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false]))
        ->toThrow(BusinessException::class, 'Isi Server Key');
});

it('tes koneksi: key salah 401 → error; benar (404 transaksi tidak ada) → terverifikasi', function () {
    [$this->apiCode, $this->apiStatus] = [401, ['status_code' => '401']];
    expect(fn () => app(TestPaymentGateway::class)->handle())->toThrow(BusinessException::class, 'Server Key ditolak');

    [$this->apiCode, $this->apiStatus] = [404, ['status_code' => '404']];
    expect(app(TestPaymentGateway::class)->handle()->verified_at)->not->toBeNull();
});

it('mulai bayar online: Snap dibuat sekali (dipakai ulang), nominal dari server', function () {
    $order = acceptedOrder($this);

    $first = app(StartOnlinePayment::class)->handle($order, 'Andi', 'https://x.test/back');
    $again = app(StartOnlinePayment::class)->handle($order, 'Andi', 'https://x.test/back');

    expect($first->id)->toBe($again->id)->and($first->amount)->toBe('28000.00')->and($first->redirect_url)->toContain('snap-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'snap/v1/transactions') && $request['transaction_details']['gross_amount'] === 28000);
    Http::assertSentCount(1);
});

it('webhook sah: order lunas dengan pembayaran Online atas nama kasir penerima, stok dipotong; kiriman ulang aman', function () {
    $order = acceptedOrder($this);
    $txn = app(StartOnlinePayment::class)->handle($order, 'Andi', 'https://x.test/back');

    notify($this, $txn)->assertOk();
    notify($this, $txn)->assertOk();

    $order->refresh();
    $payment = $order->payments()->sole();
    expect($order->status)->toBe(OrderStatus::Completed)
        ->and($payment->category)->toBe(PaymentCategory::Online)
        ->and($payment->reference)->toBe('mt-123')
        ->and($payment->user_id)->toBe($this->pos->cashier->id)
        ->and($txn->refresh()->status)->toBe(GatewayTransaction::SETTLEMENT)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(9);
});

it('webhook ditolak: tanda tangan salah, status API belum lunas, nominal beda, tenant lain', function () {
    $order = acceptedOrder($this);
    $txn = app(StartOnlinePayment::class)->handle($order, 'Andi', 'https://x.test/back');

    notify($this, $txn, signatureKey: str_repeat('a', 128))->assertForbidden();
    // Isi webhook "settlement" tetapi API Midtrans masih pending → tidak lunas
    notify($this, $txn, apiStatus: 'pending')->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::AwaitingPayment);

    notify($this, $txn, grossAmount: '1000.00', signatureKey: MidtransClient::signature($txn->id, '200', '1000.00', 'SB-Mid-server-abc'))->assertStatus(422);

    $other = posSetup();
    notify($this, $txn, tenantId: $other->tenantId)->assertNotFound();

    TenantContext::set($this->pos->tenantId);
    expect($order->refresh()->payments()->count())->toBe(0);
});

it('dibayar setelah kedaluwarsa → tetap selesai; dibayar setelah void → perlu refund manual', function () {
    Carbon::setTestNow('2026-10-08 03:00:00');
    $order = acceptedOrder($this);
    $txn = app(StartOnlinePayment::class)->handle($order, 'Andi', 'https://x.test/back');
    Carbon::setTestNow('2026-10-08 03:20:00');
    app(ExpireSelfOrders::class)->handle();
    expect($order->refresh()->status)->toBe(OrderStatus::Expired)->and($txn->refresh()->status)->toBe('cancel');

    // Pelanggan sempat membayar sebelum dibatalkan
    $txn->forceFill(['status' => GatewayTransaction::PENDING])->save();
    notify($this, $txn)->assertOk();
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    $second = acceptedOrder($this);
    $txn2 = app(StartOnlinePayment::class)->handle($second, 'Andi', 'https://x.test/back');
    app(VoidOrder::class)->handle($this->pos->supervisor, $second, 'Pelanggan pergi', null);
    notify($this, $txn2)->assertOk();
    expect($second->refresh()->refund_required)->toBeTrue()
        ->and($txn2->refresh()->status)->toBe(GatewayTransaction::REFUND_REQUIRED)
        ->and($second->payments()->count())->toBe(0);

    // Void order yang sudah lunas online → perlu refund manual
    app(VoidOrder::class)->handle($this->pos->supervisor, $order, 'Salah meja', null);
    expect($order->refresh()->refund_required)->toBeTrue();
});

it('HP: tombol bayar online mengarahkan ke Snap; tanpa gateway hanya bayar di kasir', function () {
    $order = acceptedOrder($this);
    $selfOrderId = SelfOrder::query()->where('order_id', $order->id)->value('id');
    session()->put('self_orders.'.$this->table->id, [$selfOrderId]);
    SelfOrderContext::set(SelfOrderContext::resolve($this->table->qr_token));

    Livewire::test(OrderPage::class)
        ->assertSee('Bayar online')
        ->call('payOnline', $selfOrderId)
        ->assertRedirect('https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token');

    app(UpdatePaymentGateway::class)->handle(['enabled' => false, 'is_production' => false]);
    SelfOrderContext::set(SelfOrderContext::resolve($this->table->qr_token));
    Livewire::test(OrderPage::class)->assertDontSee('Bayar online')->assertSee('Bayar di kasir');
});

it('dashboard: owner mengatur; key tidak pernah tampil; manager ditolak', function () {
    Filament::setCurrentPanel('dashboard');
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);
    CurrentOutlet::set($owner);

    Livewire::test(PaymentGatewaySettings::class)
        ->assertSet('data.server_key', null)
        ->assertDontSee('SB-Mid-server-abc')
        ->set('data.is_production', true)
        ->call('save')->assertNotified('Pembayaran online disimpan');
    expect(PaymentGatewaySetting::current()->is_production)->toBeTrue();

    $this->actingAs(User::factory()->manager()->forOutlet($this->pos->outlet)->create());
    $this->get(PaymentGatewaySettings::getUrl())->assertForbidden();
});
