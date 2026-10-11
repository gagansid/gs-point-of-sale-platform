<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Models\Order;
use App\Services\Report\CachedReportService;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/** R2: cache agregat laporan (dimatikan di test lain lewat phpunit.xml). */
beforeEach(function () {
    config(['pos.reports.cache_seconds' => 60]);
    Carbon::setTestNow('2026-10-08 05:00:00');
    $this->pos = posSetup();
    $this->range = [Carbon::parse('2026-10-08 00:00')->toImmutable(), Carbon::parse('2026-10-08 23:59')->toImmutable()];
});

afterEach(fn () => Carbon::setTestNow());

function cacheSale(stdClass $pos): void
{
    TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => 'takeaway', 'items' => [['product_id' => $pos->croissant->id, 'qty' => 1]],
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 100000]],
    ])));
}

function summaryFor(stdClass $pos, array $range, ?ReportFilters $filters = null): array
{
    TenantContext::set($pos->tenantId);
    CurrentOutlet::set(null, $pos->outlet->id);

    return app(ReportService::class)->summary($range[0], $range[1], $filters);
}

it('ReportService dari container adalah versi cache', function () {
    expect(app(ReportService::class))->toBeInstanceOf(CachedReportService::class);
});

it('angka di-cache 60 detik lalu diperbarui', function () {
    cacheSale($this->pos);
    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(1);

    cacheSale($this->pos);
    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(1);

    Carbon::setTestNow(now()->addSeconds(61));
    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(2);
});

it('kunci terpisah per tenant & per filter (tidak bocor antar tenant)', function () {
    cacheSale($this->pos);
    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(1);

    $other = posSetup();
    expect(summaryFor($other, $this->range)['order_count'])->toBe(0);

    $filtered = new ReportFilters(productIds: [uuid()]);
    expect(summaryFor($this->pos, $this->range, $filtered)['order_count'])->toBe(0);
});

it('periode yang sudah lewat di-cache lebih lama (10×)', function () {
    $past = [Carbon::parse('2026-10-01 00:00')->toImmutable(), Carbon::parse('2026-10-01 23:59')->toImmutable()];
    summaryFor($this->pos, $past);

    Carbon::setTestNow(now()->addSeconds(120));
    TenantContext::run($this->pos->tenantId, fn () => Order::query()->update(['completed_at' => '2026-10-01 05:00:00']));
    cacheSale($this->pos);
    TenantContext::run($this->pos->tenantId, fn () => Order::query()->update(['completed_at' => '2026-10-01 05:00:00']));

    expect(summaryFor($this->pos, $past)['order_count'])->toBe(0);
    Carbon::setTestNow(now()->addSeconds(600));
    expect(summaryFor($this->pos, $past)['order_count'])->toBe(1);
});

it('0 detik = tanpa cache', function () {
    config(['pos.reports.cache_seconds' => 0]);
    cacheSale($this->pos);
    summaryFor($this->pos, $this->range);
    cacheSale($this->pos);

    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(2);
});

it('Muat ulang (flush) memberi angka terbaru seketika', function () {
    cacheSale($this->pos);
    summaryFor($this->pos, $this->range);
    cacheSale($this->pos);

    CachedReportService::flush($this->pos->tenantId);
    expect(summaryFor($this->pos, $this->range)['order_count'])->toBe(2);
});
