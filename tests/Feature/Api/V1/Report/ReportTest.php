<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Models\Order;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/** Checkout langsung lewat Action pada waktu tertentu (UTC). */
function sellAt(stdClass $pos, string $utc, string $method = 'cash', int $qty = 1): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway',
        'items' => [['product_id' => $pos->croissant->id, 'qty' => $qty]],
        // 1 Croissant: 24.000 + service 1.200 + pajak 2.772 = 27.972 → 28.000; 2 Croissant: 55.944 → 55.900
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods[$method]->id, 'amount' => $method === 'cash' ? 1000000 : 28000 * $qty]],
    ]))['order']);
}

beforeEach(function () {
    $this->pos = posSetup();
    $this->owner = User::factory()->owner()->create(['tenant_id' => $this->pos->tenantId]);

    sellAt($this->pos, '2026-10-07 16:30:00');            // 7 Okt 23.30 WIB
    sellAt($this->pos, '2026-10-07 17:30:00', 'qris');    // 8 Okt 00.30 WIB (UTC masih tgl 7)
    sellAt($this->pos, '2026-10-08 05:00:00', 'cash', 2); // 8 Okt 12.00 WIB
    $voided = sellAt($this->pos, '2026-10-08 06:00:00');
    TenantContext::run($this->pos->tenantId, fn () => app(VoidOrder::class)->handle($this->pos->supervisor, $voided, 'Salah', null));

    Carbon::setTestNow('2026-10-08 10:00:00');
    TenantContext::forget();
});

function fetchReport(object $test, string $path, ?User $user = null): TestResponse
{
    freshAuth();

    return $test->withToken(userToken($user ?? $test->owner))->getJson("/api/v1/reports/{$path}", apiHeaders());
}

it('ringkasan hari ini memakai tanggal lokal outlet; void dipisah', function () {
    fetchReport($this, 'summary')
        ->assertOk()
        ->assertJsonPath('data.period', ['from' => '2026-10-08', 'to' => '2026-10-08', 'timezone' => 'Asia/Jakarta', 'outlet_id' => null])
        ->assertJsonPath('data.order_count', 2)
        ->assertJsonPath('data.revenue', '83900.00')
        ->assertJsonPath('data.average', '41950.00')
        ->assertJsonPath('data.items_sold', 3)
        ->assertJsonPath('data.void_count', 1)
        ->assertJsonPath('data.void_total', '28000.00')
        ->assertJsonPath('data.daily.0', ['date' => '2026-10-08', 'revenue' => '83900.00', 'order_count' => 2]);
});

it('rentang beberapa hari dengan seri harian', function () {
    fetchReport($this, 'summary?from=2026-10-07&to=2026-10-08')
        ->assertJsonPath('data.order_count', 3)
        ->assertJsonPath('data.revenue', '111900.00')
        ->assertJsonPath('data.daily.*.revenue', ['28000.00', '83900.00']);
});

it('per produk dan per metode bayar', function () {
    fetchReport($this, 'products')
        ->assertJsonPath('data.products.0.product_name', 'Croissant')
        ->assertJsonPath('data.products.0.qty', 3)
        ->assertJsonPath('data.products.0.revenue', '72000.00');

    fetchReport($this, 'payment-methods')
        ->assertJsonPath('data.payment_methods.0.name', 'Tunai')
        ->assertJsonPath('data.payment_methods.0.amount', '55900.00')
        ->assertJsonPath('data.payment_methods.1.name', 'QRIS')
        ->assertJsonPath('data.payment_methods.1.amount', '28000.00');
});

it('validasi rentang', function (string $query) {
    assertApiError(fetchReport($this, "summary?{$query}"), 'VALIDATION_ERROR', 422);
})->with(['from=08-10-2026', 'from=2026-10-08&to=2026-10-01', 'from=2025-01-01&to=2026-10-08']);

it('supervisor & kasir tidak punya report.view', function (string $role) {
    assertApiError(fetchReport($this, 'summary', $this->pos->{$role}), 'FORBIDDEN', 403);
})->with(['supervisor', 'cashier']);

it('isolasi: laporan tidak memuat transaksi tenant lain', function () {
    $other = posSetup();
    sellAt($other, '2026-10-08 05:00:00', 'cash', 5);
    TenantContext::forget();
    Carbon::setTestNow('2026-10-08 10:00:00');

    fetchReport($this, 'summary')->assertJsonPath('data.order_count', 2)->assertJsonPath('data.revenue', '83900.00');
});
