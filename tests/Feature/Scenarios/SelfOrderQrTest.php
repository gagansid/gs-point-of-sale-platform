<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Payment\UpdatePaymentGateway;
use App\Actions\SelfOrder\SaveDiningTable;
use App\Actions\SelfOrder\UpdateSelfOrderSettings;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentCategory;
use App\Enums\SelfOrderStatus;
use App\Livewire\SelfOrder\OrderPage;
use App\Models\DiningTable;
use App\Models\GatewayTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SelfOrder;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Payment\MidtransClient;
use App\Support\SelfOrderContext;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * Skenario Phase 3 — satu siang self-order QR meja di outlet JKT01, alur bayar dulu tanpa terima kasir (ADR 0027;
 * skenario lama ADR 0014 "kirim → kasir terima → bayar" diganti karena pesanan baru tidak pernah menunggu kasir).
 *
 * Croissant 24.000 (stok 10): 1 pcs → 24.000 + service 1.200 + pajak 11% × 25.200 = 2.772 → 27.972 → 28.000;
 * 2 pcs → 48.000 + 2.400 + 5.544 = 55.944 → 56.000. Batas waktu bayar 15 menit sejak menekan Bayar.
 * Midtrans Core API (QRIS) & status API di-fake (tanpa jaringan); server key sandbox milik owner.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-10-08 05:00:00'); // 12.00 WIB
    $this->pos = posSetup();
    TenantContext::set($this->pos->tenantId);
    app(UpdateSelfOrderSettings::class)->handle($this->pos->outlet, ['enabled' => true, 'payment_timeout_minutes' => 15]);
    $this->meja5 = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 5']);
    $this->meja6 = app(SaveDiningTable::class)->handle(null, $this->pos->outlet, ['name' => 'Meja 6']);
    RateLimiter::clear('self-order-ip:127.0.0.1');
    app(UpdatePaymentGateway::class)->handle(['enabled' => true, 'is_production' => false, 'server_key' => 'SB-Mid-server-abc', 'client_key' => 'SB-Mid-client-xyz']);

    $this->apiStatus = ['status_code' => '404'];
    $this->apiCode = 404;
    Http::fake(fn ($request) => match (true) {
        str_ends_with($request->url(), '/v2/charge') => Http::response([
            'status_code' => '201', 'transaction_id' => 'mt-qr-'.substr((string) $request['transaction_details']['order_id'], -6),
            'qr_string' => '00020101021226620014COM.GO-JEK.WWW01189360091437'.$request['transaction_details']['gross_amount'],
            'expiry_time' => '2026-10-08 12:15:00',
        ], 200),
        str_ends_with($request->url(), '/cancel') => Http::response([], 200),
        str_ends_with($request->url(), '/status') => Http::response($this->apiStatus, $this->apiCode),
        default => Http::response([], 404),
    });
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Pelanggan memesan & memilih metode bayar dari HP lewat halaman QR (Livewire): $qty Croissant atas nama $name.
 * Tagihan langsung dibuat (tanpa terima kasir).
 */
function sqPhoneOrder(object $test, DiningTable $table, string $name, int $qty, string $method = 'cashier'): Order
{
    SelfOrderContext::set(SelfOrderContext::resolve($table->qr_token));

    Livewire::test(OrderPage::class)
        ->call('addProduct', $test->pos->croissant->id)
        ->set('pickQty', $qty)
        ->call('savePick')
        ->call('show', 'cart')
        ->set('customerName', $name)
        ->call('openPay')
        ->call('choosePay', $method)
        ->assertSet('payError', null)
        ->assertSet('view', 'pay')
        ->assertSee($method === 'qris' ? 'Berlaku' : 'Tunjukkan ke kasir');

    TenantContext::set($test->pos->tenantId);

    return Order::query()->findOrFail(SelfOrder::query()->where('customer_name', $name)->sole()->order_id);
}

function sqApi(object $test, string $method, string $uri, array $body = [], ?string $token = null): TestResponse
{
    freshAuth();
    $response = $test->withToken($token ?? $test->pos->token)->json($method, '/api/v1'.$uri, $body, apiHeaders());
    TenantContext::set($test->pos->tenantId);

    return $response;
}

/** Notifikasi Midtrans ke webhook tenant; status API Midtrans (sumber kebenaran) di-fake "settlement". */
function sqWebhook(object $test, GatewayTransaction $txn, ?string $signature = null): TestResponse
{
    $gross = number_format((float) $txn->amount, 2, '.', '');
    $test->apiCode = 200;
    $test->apiStatus = [
        'order_id' => $txn->id, 'transaction_status' => 'settlement', 'fraud_status' => 'accept', 'status_code' => '200',
        'gross_amount' => $gross, 'transaction_id' => 'mt-777', 'payment_type' => 'qris',
    ];
    TenantContext::forget();

    $response = $test->postJson("/webhooks/midtrans/{$test->pos->tenantId}", [
        'order_id' => $txn->id, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'settlement',
        'signature_key' => $signature ?? MidtransClient::signature($txn->id, '200', $gross, 'SB-Mid-server-abc'),
    ]);
    TenantContext::set($test->pos->tenantId);

    return $response;
}

it('siang self-order: bayar di kasir, QRIS di halaman (webhook sah/palsu/ganda), kedaluwarsa → status & laporan kanal cocok', function () {
    // ── 1. Halaman QR Meja 5 terbuka di HP ───────────────────────────────────────────────────────────────────
    $this->get($this->meja5->orderUrl())->assertOk()->assertSee('Meja 5')->assertSee('Croissant');

    // ── 2a. Andi (Meja 5) pesan 2 Croissant, pilih bayar di kasir → tagihan langsung, tanpa terima kasir ──────
    $andi = sqPhoneOrder($this, $this->meja5, 'Andi', 2);
    expect($andi->status)->toBe(OrderStatus::AwaitingPayment)
        ->and($andi->grand_total)->toBe('56000.00') // 2 × 24.000 → 56.000
        ->and($andi->table_label)->toBe('Meja 5')
        ->and($andi->shift_id)->toBe($this->pos->shift->id)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);

    // Tidak ada pesanan yang menunggu diterima; tagihan tampil di "Belum dibayar" aplikasi kasir
    sqApi($this, 'GET', '/self-orders')->assertOk()->assertJsonPath('meta.pending_count', 0);
    sqApi($this, 'GET', '/orders?status=awaiting_payment&channel=self_order')->assertOk()->assertJsonPath('data.0.id', $andi->id);

    // Andi bayar tunai di kasir: 60.000 untuk 56.000 → kembali 4.000; stok 10 − 2 = 8
    $payBody = ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 60000]]];
    sqApi($this, 'POST', "/orders/{$andi->id}/payments", $payBody)->assertCreated()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.change_total', '4000.00');
    expect(stockOf($this->pos->croissant)->stock_qty)->toBe(8);

    // ── 2b. Budi (Meja 6) pesan 1 Croissant, QRIS tampil di halaman ───────────────────────────────────────────
    $budi = sqPhoneOrder($this, $this->meja6, 'Budi', 1, 'qris');
    $txn = GatewayTransaction::query()->where('order_id', $budi->id)->sole();
    // Nominal QRIS dari server (28.000), bukan dari HP; satu QR aktif
    expect($txn->amount)->toBe('28000.00')->and($txn->method)->toBe(GatewayTransaction::QRIS)->and($txn->isQrActive())->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/charge') && $request['payment_type'] === 'qris'
        && $request['transaction_details']['gross_amount'] === 28000);

    // Tanda tangan palsu → 403, order tetap menunggu bayar
    sqWebhook($this, $txn, str_repeat('a', 128))->assertForbidden();
    expect($budi->refresh()->status)->toBe(OrderStatus::AwaitingPayment)->and($budi->payments()->count())->toBe(0);

    // Notifikasi sah lalu kiriman ulang Midtrans → lunas sekali, satu pembayaran, stok dipotong sekali (8 − 1 = 7)
    sqWebhook($this, $txn)->assertOk();
    sqWebhook($this, $txn)->assertOk();
    $budi->refresh();
    expect($budi->status)->toBe(OrderStatus::Completed)
        ->and($budi->paid_total)->toBe('28000.00')
        ->and(Payment::query()->where('order_id', $budi->id)->count())->toBe(1)
        ->and(Payment::query()->where('order_id', $budi->id)->sole()->category)->toBe(PaymentCategory::Online)
        ->and($txn->refresh()->status)->toBe(GatewayTransaction::SETTLEMENT)
        ->and(StockMovement::query()->where('reference_id', $budi->id)->count())->toBe(1)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(7);

    // ── 3. Penjualan kasir biasa (kanal pos): 1 Croissant 28.000 tunai → stok 6 ─────────────────────────────
    app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]],
    ]));

    // ── 4. Kedaluwarsa: Citra (bayar di kasir) & Dewi (QRIS) tidak membayar ────────────────────────────────
    $citra = sqPhoneOrder($this, $this->meja5, 'Citra', 1);
    $dewi = sqPhoneOrder($this, $this->meja6, 'Dewi', 1, 'qris');
    $dewiQr = GatewayTransaction::query()->where('order_id', $dewi->id)->sole();

    // 12.16 WIB: lewat 15 menit → command terjadwal mengedaluwarsakan 2 tagihan & membatalkan QR; diulang = 0
    Carbon::setTestNow('2026-10-08 05:16:00');
    $this->artisan('pos:expire-self-orders')->expectsOutput('2 pesanan meja kedaluwarsa.')->assertSuccessful();
    $this->artisan('pos:expire-self-orders')->expectsOutput('0 pesanan meja kedaluwarsa.')->assertSuccessful();
    TenantContext::set($this->pos->tenantId);

    expect($dewiQr->refresh()->status)->toBe('cancel');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/'.$dewiQr->id.'/cancel'));
    assertApiError(sqApi($this, 'POST', "/orders/{$citra->id}/payments", [
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]],
    ]), 'ORDER_EXPIRED', 409);

    // ── 5. Status akhir ─────────────────────────────────────────────────────────────────────────────────────
    expect($citra->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($dewi->refresh()->status)->toBe(OrderStatus::Expired)
        ->and($andi->refresh()->status)->toBe(OrderStatus::Completed)
        ->and(SelfOrder::query()->count())->toBe(4)
        ->and(SelfOrder::query()->where('status', SelfOrderStatus::Accepted)->count())->toBe(4)
        // 5 order: Andi, Budi, kasir (selesai) + Citra & Dewi (kedaluwarsa); stok tetap 6
        ->and(Order::query()->where('status', OrderStatus::Completed)->count())->toBe(3)
        ->and(Order::query()->where('channel', OrderChannel::SelfOrder)->count())->toBe(4)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(6);

    // ── 6. Laporan kanal hari ini (owner) ───────────────────────────────────────────────────────────────────
    $channels = collect(sqApi($this, 'GET', '/reports/channels?from=2026-10-08&to=2026-10-08', token: userToken(User::factory()->owner()->create()))
        ->assertOk()->json('data.channels'))->keyBy('channel');
    // Kasir: 1 order 28.000. QR meja: Andi 56.000 + Budi 28.000 = 84.000 (online 28.000); yang kedaluwarsa tidak dihitung
    expect($channels['pos'])->toMatchArray(['order_count' => 1, 'revenue' => '28000.00', 'online_total' => '0.00'])
        ->and($channels['self_order'])->toMatchArray(['order_count' => 2, 'revenue' => '84000.00', 'online_total' => '28000.00']);
});

it('webhook tenant lain dengan transaksi tenant ini ditolak dan tidak membayar order', function () {
    $orderId = sqPhoneOrder($this, $this->meja5, 'Andi', 1, 'qris')->id;
    $txn = GatewayTransaction::query()->where('order_id', $orderId)->sole();
    $other = posSetup();
    $gross = number_format((float) $txn->amount, 2, '.', '');
    TenantContext::forget();

    $this->postJson("/webhooks/midtrans/{$other->tenantId}", [
        'order_id' => $txn->id, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'settlement',
        'signature_key' => MidtransClient::signature($txn->id, '200', $gross, 'SB-Mid-server-abc'),
    ])->assertNotFound();

    TenantContext::set($this->pos->tenantId);
    expect(Order::query()->findOrFail($orderId)->status)->toBe(OrderStatus::AwaitingPayment)
        ->and(Payment::query()->where('order_id', $orderId)->count())->toBe(0)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);
});
