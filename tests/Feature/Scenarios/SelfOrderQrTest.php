<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Payment\StartOnlinePayment;
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
 * Skenario Phase 3 — satu siang self-order QR meja di outlet JKT01 (ADR 0014).
 *
 * Croissant 24.000 (stok 10): 1 pcs → 24.000 + service 1.200 + pajak 11% × 25.200 = 2.772 → 27.972 → 28.000;
 * 2 pcs → 48.000 + 2.400 + 5.544 = 55.944 → 55.900. Batas waktu bayar/tangani 15 menit.
 * Midtrans Snap & status API di-fake (tanpa jaringan); server key sandbox milik owner.
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
        str_contains($request->url(), '/snap/v1/transactions') => Http::response(['token' => 'snap-token', 'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/snap-token'], 201),
        str_ends_with($request->url(), '/cancel') => Http::response([], 200),
        str_ends_with($request->url(), '/status') => Http::response($this->apiStatus, $this->apiCode),
        default => Http::response([], 404),
    });
});

afterEach(fn () => Carbon::setTestNow());

/** Pelanggan memesan dari HP lewat halaman QR (Livewire): $qty Croissant atas nama $name. */
function sqPhoneOrder(object $test, DiningTable $table, string $name, int $qty): SelfOrder
{
    SelfOrderContext::set(SelfOrderContext::resolve($table->qr_token));

    Livewire::test(OrderPage::class)
        ->call('addProduct', $test->pos->croissant->id)
        ->set('pickQty', $qty)
        ->call('savePick')
        ->call('show', 'cart')
        ->set('customerName', $name)
        ->call('submit')
        ->assertSet('error', null)
        ->assertSet('view', 'status')
        ->assertSee('Menunggu kasir');

    TenantContext::set($test->pos->tenantId);

    return SelfOrder::query()->where('customer_name', $name)->sole();
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

it('siang self-order: QR → bayar di kasir, bayar online (webhook sah/palsu/ganda), kedaluwarsa → status & laporan kanal cocok', function () {
    // ── 1. Halaman QR Meja 5 terbuka di HP ───────────────────────────────────────────────────────────────────
    $this->get($this->meja5->orderUrl())->assertOk()->assertSee('Meja 5')->assertSee('Croissant');

    // ── 2a. Andi (Meja 5) pesan 2 Croissant → menunggu kasir; stok belum dipotong ─────────────────────────────
    $andi = sqPhoneOrder($this, $this->meja5, 'Andi', 2);
    expect($andi->status)->toBe(SelfOrderStatus::Pending)
        ->and($andi->estimated_total)->toBe('55900.00') // 2 × 24.000 → 55.900
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(10);

    sqApi($this, 'GET', '/self-orders')->assertOk()->assertJsonPath('meta.pending_count', 1)->assertJsonPath('data.0.id', $andi->id);

    // Kasir menerima lewat API; diterima dua kali = 409
    $andiOrderId = sqApi($this, 'POST', "/self-orders/{$andi->id}/accept")->assertCreated()
        ->assertJsonPath('data.status', 'awaiting_payment')->assertJsonPath('data.table_label', 'Meja 5')
        ->assertJsonPath('data.grand_total', '55900.00')->json('data.id');
    assertApiError(sqApi($this, 'POST', "/self-orders/{$andi->id}/accept"), 'ORDER_NOT_PENDING', 409);
    expect(stockOf($this->pos->croissant)->stock_qty)->toBe(10);

    // Andi bayar tunai di kasir: 60.000 untuk 55.900 → kembali 4.100; stok 10 − 2 = 8
    $payBody = ['payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 60000]]];
    sqApi($this, 'POST', "/orders/{$andiOrderId}/payments", $payBody)->assertCreated()
        ->assertJsonPath('data.status', 'completed')->assertJsonPath('data.change_total', '4100.00');
    expect(stockOf($this->pos->croissant)->stock_qty)->toBe(8);

    // ── 2b. Budi (Meja 6) pesan 1 Croissant, diterima kasir, bayar online ─────────────────────────────────────
    $budi = sqPhoneOrder($this, $this->meja6, 'Budi', 1);
    $budiOrderId = sqApi($this, 'POST', "/self-orders/{$budi->id}/accept")->assertCreated()->assertJsonPath('data.grand_total', '28000.00')->json('data.id');
    $budiOrder = Order::query()->findOrFail($budiOrderId);

    $txn = app(StartOnlinePayment::class)->handle($budiOrder, 'Budi', 'https://x.test/back');
    // Nominal Snap dari server (28.000), bukan dari HP
    expect($txn->amount)->toBe('28000.00')->and($txn->redirect_url)->toContain('snap-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'snap/v1/transactions') && $request['transaction_details']['gross_amount'] === 28000);

    // Tanda tangan palsu → 403, order tetap menunggu bayar
    sqWebhook($this, $txn, str_repeat('a', 128))->assertForbidden();
    expect($budiOrder->refresh()->status)->toBe(OrderStatus::AwaitingPayment)->and($budiOrder->payments()->count())->toBe(0);

    // Notifikasi sah lalu kiriman ulang Midtrans → lunas sekali, satu pembayaran, stok dipotong sekali (8 − 1 = 7)
    sqWebhook($this, $txn)->assertOk();
    sqWebhook($this, $txn)->assertOk();
    $budiOrder->refresh();
    expect($budiOrder->status)->toBe(OrderStatus::Completed)
        ->and($budiOrder->paid_total)->toBe('28000.00')
        ->and(Payment::query()->where('order_id', $budiOrderId)->count())->toBe(1)
        ->and(Payment::query()->where('order_id', $budiOrderId)->sole()->category)->toBe(PaymentCategory::Online)
        ->and($txn->refresh()->status)->toBe(GatewayTransaction::SETTLEMENT)
        ->and(StockMovement::query()->where('reference_id', $budiOrderId)->count())->toBe(1)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(7);

    // ── 3. Penjualan kasir biasa (kanal pos): 1 Croissant 28.000 tunai → stok 6 ─────────────────────────────
    app(CheckoutOrder::class)->handle($this->pos->cashier, $this->pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $this->pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]],
    ]));

    // ── 4. Kedaluwarsa: Citra (Meja 5) tidak ditangani; Dewi (Meja 6) diterima tetapi tidak dibayar ──────────
    $citra = sqPhoneOrder($this, $this->meja5, 'Citra', 1);
    $dewi = sqPhoneOrder($this, $this->meja6, 'Dewi', 1);
    $dewiOrderId = sqApi($this, 'POST', "/self-orders/{$dewi->id}/accept")->assertCreated()->json('data.id');

    // 12.16 WIB: lewat 15 menit → command terjadwal mengedaluwarsakan 1 pesanan + 1 order; diulang = 0
    Carbon::setTestNow('2026-10-08 05:16:00');
    $this->artisan('pos:expire-self-orders')->expectsOutput('2 pesanan meja kedaluwarsa.')->assertSuccessful();
    $this->artisan('pos:expire-self-orders')->expectsOutput('0 pesanan meja kedaluwarsa.')->assertSuccessful();
    TenantContext::set($this->pos->tenantId);

    assertApiError(sqApi($this, 'POST', "/self-orders/{$citra->id}/accept"), 'ORDER_EXPIRED', 409);
    assertApiError(sqApi($this, 'POST', "/orders/{$dewiOrderId}/payments", [
        'payments' => [['id' => uuid(), 'payment_method_id' => $this->pos->methods['cash']->id, 'amount' => 28000]],
    ]), 'ORDER_EXPIRED', 409);

    // ── 5. Status akhir ─────────────────────────────────────────────────────────────────────────────────────
    expect($citra->refresh()->status)->toBe(SelfOrderStatus::Expired)
        ->and($dewi->refresh()->status)->toBe(SelfOrderStatus::Accepted)
        ->and(Order::query()->findOrFail($dewiOrderId)->status)->toBe(OrderStatus::Expired)
        ->and(Order::query()->findOrFail($andiOrderId)->status)->toBe(OrderStatus::Completed)
        ->and(SelfOrder::query()->count())->toBe(4)
        // 4 order: Andi, Budi, kasir (selesai) + Dewi (kedaluwarsa); stok tetap 6 (order kedaluwarsa tidak memotong)
        ->and(Order::query()->where('status', OrderStatus::Completed)->count())->toBe(3)
        ->and(Order::query()->where('channel', OrderChannel::SelfOrder)->count())->toBe(3)
        ->and(stockOf($this->pos->croissant)->stock_qty)->toBe(6);

    // ── 6. Laporan kanal hari ini (owner) ───────────────────────────────────────────────────────────────────
    $channels = collect(sqApi($this, 'GET', '/reports/channels?from=2026-10-08&to=2026-10-08', token: userToken(User::factory()->owner()->create()))
        ->assertOk()->json('data.channels'))->keyBy('channel');
    // Kasir: 1 order 28.000. QR meja: Andi 55.900 + Budi 28.000 = 83.900 (online 28.000); Dewi kedaluwarsa tidak dihitung
    expect($channels['pos'])->toMatchArray(['order_count' => 1, 'revenue' => '28000.00', 'online_total' => '0.00'])
        ->and($channels['self_order'])->toMatchArray(['order_count' => 2, 'revenue' => '83900.00', 'online_total' => '28000.00']);
});

it('webhook tenant lain dengan transaksi tenant ini ditolak dan tidak membayar order', function () {
    $selfOrder = sqPhoneOrder($this, $this->meja5, 'Andi', 1);
    $orderId = sqApi($this, 'POST', "/self-orders/{$selfOrder->id}/accept")->assertCreated()->json('data.id');
    $txn = app(StartOnlinePayment::class)->handle(Order::query()->findOrFail($orderId), 'Andi', 'https://x.test/back');
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
