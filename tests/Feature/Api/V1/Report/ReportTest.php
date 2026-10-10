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

// ---------- Beranda: /reports/dashboard, /reports/hourly, /reports/audit ----------

it('dashboard: KPI dibanding periode sebelumnya, tren per jam untuk 1 hari', function () {
    fetchReport($this, 'dashboard')
        ->assertOk()
        ->assertJsonPath('data.period.granularity', 'hour')
        ->assertJsonPath('data.comparison', ['from' => '2026-10-07', 'to' => '2026-10-07'])
        ->assertJsonPath('data.summary.revenue', '83900.00')
        ->assertJsonPath('data.previous_summary.revenue', '28000.00')
        // (83.900 − 28.000) ÷ 28.000 = 199,64%
        ->assertJsonPath('data.changes.revenue', '199.6')
        ->assertJsonPath('data.changes.order_count', '100.0')
        ->assertJsonPath('data.trend.0', ['hour' => 0, 'revenue' => '28000.00', 'order_count' => 1])
        ->assertJsonPath('data.trend.12', ['hour' => 12, 'revenue' => '55900.00', 'order_count' => 1])
        ->assertJsonPath('data.previous_trend.23.revenue', '28000.00')
        ->assertJsonPath('data.order_types.0', ['order_type' => 'takeaway', 'label' => 'Bawa pulang', 'order_count' => 2, 'revenue' => '83900.00'])
        ->assertJsonPath('data.order_types.1.order_count', 0)
        ->assertJsonPath('data.top_products.0.product_name', 'Croissant')
        ->assertJsonPath('data.outlets.0.revenue', '83900.00');
});

it('dashboard: rentang beberapa hari = tren harian; compare none / tahun lalu', function () {
    fetchReport($this, 'dashboard?from=2026-10-07&to=2026-10-08&compare=none')
        ->assertJsonPath('data.period.granularity', 'day')
        ->assertJsonPath('data.trend.*.revenue', ['28000.00', '83900.00'])
        ->assertJsonPath('data.comparison', null)
        ->assertJsonPath('data.previous_summary', null)
        ->assertJsonPath('data.changes.revenue', null);

    fetchReport($this, 'dashboard?compare=previous_year')
        ->assertJsonPath('data.comparison', ['from' => '2025-10-08', 'to' => '2025-10-08'])
        // Pembanding 0 → persen tidak terdefinisi
        ->assertJsonPath('data.changes.revenue', null);
});

it('filter metode bayar, tipe order, dan kasir membatasi angka', function () {
    $qris = $this->pos->methods['qris']->id;

    fetchReport($this, "dashboard?payment_method_ids[]={$qris}")
        ->assertJsonPath('data.summary.order_count', 1)
        ->assertJsonPath('data.summary.revenue', '28000.00')
        ->assertJsonPath('data.payment_methods.0.name', 'QRIS')
        ->assertJsonCount(1, 'data.payment_methods');

    fetchReport($this, 'summary?order_type=dine_in')->assertJsonPath('data.order_count', 0);
    fetchReport($this, 'summary?user_ids[]='.$this->pos->cashier->id)->assertJsonPath('data.order_count', 2);
    fetchReport($this, 'summary?user_ids[]='.uuid())->assertJsonPath('data.order_count', 0)->assertJsonPath('data.void_count', 0);
});

it('jam ramai per jam lokal outlet', function () {
    fetchReport($this, 'hourly?from=2026-10-07&to=2026-10-08')
        ->assertOk()
        ->assertJsonCount(24, 'data.hours')
        ->assertJsonPath('data.hours.0.order_count', 1)
        ->assertJsonPath('data.hours.12.revenue', '55900.00')
        ->assertJsonPath('data.hours.23.order_count', 1);
});

it('audit: void dengan pelaku & alasan', function () {
    fetchReport($this, 'audit')
        ->assertOk()
        ->assertJsonPath('data.void_count', 1)
        ->assertJsonPath('data.void_total', '28000.00')
        ->assertJsonPath('data.voids.0.voided_by', 'Sari')
        ->assertJsonPath('data.voids.0.reason', 'Salah')
        ->assertJsonPath('data.discount_count', 0)
        ->assertJsonPath('data.shift_issue_count', 0);
});

it('audit: shift ditutup dengan selisih kas tercatat', function () {
    TenantContext::run($this->pos->tenantId, fn () => $this->pos->shift->forceFill([
        'status' => 'closed', 'expected_cash' => '300000.00', 'actual_cash' => '290000.00',
        'difference' => '-10000.00', 'closed_by' => $this->pos->cashier->id, 'closed_at' => '2026-10-08 09:00:00',
    ])->save());

    fetchReport($this, 'audit')
        ->assertJsonPath('data.shift_issue_count', 1)
        ->assertJsonPath('data.shift_difference_total', '-10000.00')
        ->assertJsonPath('data.shifts.0.cashier', 'Budi')
        ->assertJsonPath('data.shifts.0.difference', '-10000.00');
});

it('validasi filter dashboard', function (string $query) {
    assertApiError(fetchReport($this, "dashboard?{$query}"), 'VALIDATION_ERROR', 422);
})->with(['compare=kemarin', 'order_type=delivery', 'payment_method_ids[]=bukan-uuid', 'user_ids=abc']);

it('dashboard, jam ramai & audit butuh report.view', function (string $path) {
    assertApiError(fetchReport($this, $path, $this->pos->cashier), 'FORBIDDEN', 403);
    assertApiError(fetchReport($this, $path, $this->pos->supervisor), 'FORBIDDEN', 403);
})->with(['dashboard', 'hourly', 'audit']);

it('isolasi: dashboard & audit tidak memuat data tenant lain; filter ID asing tidak bocor', function () {
    $other = posSetup();
    $foreign = sellAt($other, '2026-10-08 05:00:00', 'cash', 5);
    TenantContext::run($other->tenantId, fn () => app(VoidOrder::class)->handle($other->supervisor, sellAt($other, '2026-10-08 06:00:00'), 'Asing', null));
    TenantContext::forget();
    Carbon::setTestNow('2026-10-08 10:00:00');

    fetchReport($this, 'dashboard')
        ->assertJsonPath('data.summary.revenue', '83900.00')
        ->assertJsonCount(1, 'data.outlets');
    fetchReport($this, 'audit')->assertJsonPath('data.void_count', 1)->assertJsonMissing(['reason' => 'Asing']);
    fetchReport($this, 'dashboard?payment_method_ids[]='.$other->methods['cash']->id.'&user_ids[]='.$foreign->user_id)
        ->assertJsonPath('data.summary.order_count', 0);
});

it('request ganda menghasilkan angka yang sama (hanya baca)', function () {
    $first = fetchReport($this, 'dashboard')->json('data');
    $second = fetchReport($this, 'dashboard')->json('data');

    expect($second)->toBe($first)->and(Order::allTenants()->count())->toBe(4);
});
