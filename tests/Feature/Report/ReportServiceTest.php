<?php

declare(strict_types=1);

use App\Actions\Order\CheckoutOrder;
use App\Actions\Order\Data\CheckoutData;
use App\Actions\Order\VoidOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Report\ReportFilters;
use App\Services\Report\ReportPeriod;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Agregasi laporan baru (SPEC Q49–Q51): tren per granularitas, kategori (snapshot), opsi,
 * karyawan, shift, heatmap, stok, filter produk/kategori/shift, isolasi tenant.
 */
function reportSale(stdClass $pos, string $utc, array $items, string $type = 'takeaway'): Order
{
    Carbon::setTestNow($utc);

    return TenantContext::run($pos->tenantId, fn () => app(CheckoutOrder::class)->handle($pos->cashier, $pos->device, CheckoutData::fromArray([
        'id' => uuid(), 'order_type' => $type, 'items' => $items,
        'payments' => [['id' => uuid(), 'payment_method_id' => $pos->methods['cash']->id, 'amount' => 500000]],
    ]))['order']);
}

/** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
function localRange(string $from, string $to): array
{
    return (new ReportPeriod($from, $to))->utcRange();
}

beforeEach(function () {
    $this->pos = posSetup();
    TenantContext::run($this->pos->tenantId, function () {
        $this->kopi = Category::factory()->create(['name' => 'Kopi']);
        $this->pos->coffee->update(['category_id' => $this->kopi->id]);
        $this->pos->shift->forceFill(['opened_at' => '2026-10-04 01:00:00'])->save();
    });

    $coffeeLarge = ['product_id' => $this->pos->coffee->id, 'qty' => 2, 'option_ids' => [$this->pos->large->id]];
    $croissant = ['product_id' => $this->pos->croissant->id, 'qty' => 1];

    // Senin 5 Okt 10.00 WIB, Minggu 4 Okt 23.30 WIB (minggu ISO sebelumnya), Rabu 7 Okt 00.30 WIB
    $this->monday = reportSale($this->pos, '2026-10-05 03:00:00', [$coffeeLarge], 'dine_in');
    $this->sunday = reportSale($this->pos, '2026-10-04 16:30:00', [$croissant]);
    $this->wednesday = reportSale($this->pos, '2026-10-06 17:30:00', [$croissant, $coffeeLarge]);
    $voided = reportSale($this->pos, '2026-10-06 18:00:00', [$croissant]);
    TenantContext::run($this->pos->tenantId, fn () => app(VoidOrder::class)->handle($this->pos->supervisor, $voided, 'Salah input', null));

    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set(null, $this->pos->outlet->id);
    $this->reports = app(ReportService::class);
});

afterEach(fn () => Carbon::setTestNow());

it('tren per minggu memakai Senin (ISO) dan zona waktu outlet', function () {
    $trend = $this->reports->trend(...[...localRange('2026-09-28', '2026-10-10'), 'Asia/Jakarta', 'week']);

    expect(array_column($trend, 'key'))->toBe(['2026-09-28', '2026-10-05'])
        // Minggu 4 Okt 23.30 WIB masuk minggu 28 Sep, bukan 5 Okt
        ->and($trend[0]['order_count'])->toBe(1)
        ->and($trend[1]['order_count'])->toBe(2)
        ->and($trend[1]['to'])->toBe('2026-10-10');
});

it('tren per bulan & per jam', function () {
    $months = $this->reports->trend(...[...localRange('2026-08-15', '2026-10-10'), 'Asia/Jakarta', 'month']);
    expect(array_column($months, 'label'))->toBe(['Agt 2026', 'Sep 2026', 'Okt 2026'])
        ->and($months[2]['order_count'])->toBe(3)
        ->and($months[0]['from'])->toBe('2026-08-15');

    $hours = $this->reports->trend(...[...localRange('2026-10-05', '2026-10-05'), 'Asia/Jakarta', 'hour']);
    expect($hours)->toHaveCount(24)->and($hours[10]['order_count'])->toBe(1)->and($hours[10]['label'])->toBe('10.00');
});

it('rincian finansial: bruto, penjualan bersih, void terpisah', function () {
    $s = $this->reports->summary(...localRange('2026-10-01', '2026-10-10'));

    expect($s['order_count'])->toBe(3)
        ->and($s['void_count'])->toBe(1)
        ->and($s['subtotal_total'])->toBe('156000.00') // 54.000 + 24.000 + 78.000
        ->and($s['net_sales'])->toBe(bcsub(bcsub(bcsub($s['revenue'], $s['tax_total'], 2), $s['service_total'], 2), $s['rounding_total'], 2));
});

it('kategori memakai snapshot: ganti nama kategori tidak mengubah laporan lama', function () {
    $this->kopi->update(['name' => 'Minuman']);
    $rows = collect($this->reports->categories(...localRange('2026-10-01', '2026-10-10')))->keyBy('category_name');

    expect($rows->keys()->sort()->values()->all())->toBe(['Kopi', 'Tanpa kategori'])
        ->and($rows['Kopi']['qty'])->toBe(4)
        ->and($rows['Tanpa kategori']['qty'])->toBe(2);

    expect(OrderItem::query()->where('order_id', $this->monday->id)->value('category_name'))->toBe('Kopi');
});

it('opsi: dipilih × qty item dan tambahan omzet', function () {
    $rows = $this->reports->options(...localRange('2026-10-01', '2026-10-10'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['option_name'])->toBe('Large')
        ->and($rows[0]['qty'])->toBe(4)
        ->and($rows[0]['revenue'])->toBe('20000.00');
});

it('karyawan, shift, heatmap', function () {
    $employees = $this->reports->employees(...localRange('2026-10-01', '2026-10-10'));
    expect($employees[0]['name'])->toBe('Budi')->and($employees[0]['order_count'])->toBe(3)->and($employees[0]['void_count'])->toBe(1);

    $shifts = $this->reports->shifts(...localRange('2026-01-01', '2026-10-10'));
    $sales = Order::query()->where('status', 'completed')->sum('grand_total');
    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]['cashier'])->toBe('Budi')
        ->and($shifts[0]['order_count'])->toBe(3)
        ->and($shifts[0]['cash_sales'])->toBe(number_format((float) $sales, 2, '.', ''))
        ->and($shifts[0]['expected_cash'])->toBe(bcadd('200000.00', $shifts[0]['cash_sales'], 2))
        ->and($shifts[0]['difference'])->toBeNull();

    $heat = $this->reports->heatmap(...[...localRange('2026-10-01', '2026-10-10'), 'Asia/Jakarta']);
    expect($heat[1][10])->toBe(1)   // Senin 10.00
        ->and($heat[7][23])->toBe(1) // Minggu 23.30
        ->and($heat[3][0])->toBe(1); // Rabu 00.30
});

it('stok: terjual, kembali dari void, stok kini', function () {
    $rows = collect($this->reports->stock(...localRange('2026-10-01', '2026-10-10')))->keyBy('product_name');

    // Croissant stok awal 10: terjual 3, 1 kembali karena void → stok 8
    expect($rows['Croissant']['sold'])->toBe(3)
        ->and($rows['Croissant']['void_return'])->toBe(1)
        ->and($rows['Croissant']['stock'])->toBe(8)
        ->and($rows->has('Es Kopi Susu'))->toBeFalse();
});

it('filter produk, kategori, dan shift', function () {
    [$start, $end] = localRange('2026-10-01', '2026-10-10');

    $byProduct = new ReportFilters(productIds: [$this->pos->croissant->id]);
    expect($this->reports->summary($start, $end, $byProduct)['order_count'])->toBe(2)
        ->and(array_column($this->reports->products($start, $end, null, $byProduct), 'product_name'))->toBe(['Croissant']);

    $byCategory = new ReportFilters(categoryIds: [$this->kopi->id]);
    expect(array_column($this->reports->categories($start, $end, $byCategory), 'category_name'))->toBe(['Kopi'])
        ->and($this->reports->summary($start, $end, $byCategory)['order_count'])->toBe(2);

    expect($this->reports->summary($start, $end, new ReportFilters(shiftIds: [uuid()]))['order_count'])->toBe(0)
        ->and($this->reports->summary($start, $end, new ReportFilters(shiftIds: [$this->pos->shift->id]))['order_count'])->toBe(3);
});

it('isolasi tenant: agregasi baru tidak memuat data tenant lain', function () {
    $other = posSetup();
    reportSale($other, '2026-10-05 03:00:00', [['product_id' => $other->croissant->id, 'qty' => 5]]);
    Carbon::setTestNow('2026-10-10 05:00:00');
    TenantContext::set($this->pos->tenantId);
    CurrentOutlet::set(null, $this->pos->outlet->id);
    [$start, $end] = localRange('2026-10-01', '2026-10-10');

    expect(collect($this->reports->categories($start, $end))->sum('qty'))->toBe(6)
        ->and(collect($this->reports->employees($start, $end))->pluck('name')->all())->toBe(['Budi'])
        ->and($this->reports->shifts($start, $end))->toHaveCount(1)
        ->and($this->reports->summary($start, $end, new ReportFilters(productIds: [$other->croissant->id]))['order_count'])->toBe(0);
});
