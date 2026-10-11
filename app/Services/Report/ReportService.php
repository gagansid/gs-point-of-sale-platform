<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentCategory;
use App\Enums\PaymentStatus;
use App\Enums\PointEntryType;
use App\Enums\ShiftStatus;
use App\Enums\StockMovementType;
use App\Models\Member;
use App\Models\MemberPointEntry;
use App\Models\MemberSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya sumber angka laporan (API /reports, beranda dashboard, halaman Laporan, Excel).
 * Hanya baca; isolasi tenant otomatis lewat TenantScope, outlet mengikuti CurrentOutlet
 * (outlet terpilih, atau semua outlet yang boleh diakses — ADR 0010).
 *
 * - Omzet diakui saat order SELESAI (completed_at), bukan saat dibuat.
 * - Order void tidak masuk omzet; dilaporkan terpisah berdasarkan voided_at.
 * - Rentang waktu dalam UTC (konversi dari tanggal lokal outlet lewat CurrentOutlet::utcRange).
 * - ReportFilters (opsional): tipe order, metode bayar, kasir.
 */
class ReportService
{
    /**
     * Rincian finansial: subtotal_total (bruto) − discount_total = penjualan; + service + pajak + pembulatan
     * = revenue (omzet diterima). net_sales = revenue − pajak − service − pembulatan (tanpa pajak & service,
     * berlaku untuk pajak inklusif maupun eksklusif).
     *
     * @return array{order_count: int, revenue: string, average: string, items_sold: int, subtotal_total: string, discount_total: string,
     *     service_total: string, tax_total: string, rounding_total: string, net_sales: string, void_count: int, void_total: string}
     */
    public function summary(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $totals = $this->completed($start, $end, $filters)
            ->toBase()
            ->selectRaw('COUNT(*) as order_count, SUM(grand_total) as revenue, SUM(subtotal) as subtotal_total, SUM(discount_total) as discount_total,
                SUM(service_total) as service_total, SUM(tax_total) as tax_total, SUM(rounding) as rounding_total')
            ->first();

        $voids = $this->voided($start, $end, $filters)
            ->toBase()
            ->selectRaw('COUNT(*) as void_count, SUM(grand_total) as void_total')
            ->first();

        $orderCount = (int) ($totals->order_count ?? 0);
        $revenue = Money::fromDb($totals->revenue ?? null);
        $service = Money::fromDb($totals->service_total ?? null);
        $tax = Money::fromDb($totals->tax_total ?? null);
        $rounding = Money::fromDb($totals->rounding_total ?? null);

        return [
            'order_count' => $orderCount,
            'revenue' => $revenue,
            'average' => Money::div($revenue, $orderCount),
            'items_sold' => (int) OrderItem::query()->whereIn('order_id', $this->completed($start, $end, $filters)->select('id'))->sum('qty'),
            'subtotal_total' => Money::fromDb($totals->subtotal_total ?? null),
            'discount_total' => Money::fromDb($totals->discount_total ?? null),
            'service_total' => $service,
            'tax_total' => $tax,
            'rounding_total' => $rounding,
            'net_sales' => Money::sub(Money::sub(Money::sub($revenue, $tax), $service), $rounding),
            'void_count' => (int) ($voids->void_count ?? 0),
            'void_total' => Money::fromDb($voids->void_total ?? null),
        ];
    }

    /**
     * Penjualan per produk, urut omzet terbesar. Nama dari snapshot transaksi terakhir.
     *
     * @return list<array{product_id: string, product_name: string, qty: int, revenue: string}>
     */
    public function products(CarbonImmutable $start, CarbonImmutable $end, ?int $limit = null, ?ReportFilters $filters = null): array
    {
        return $this->items($start, $end, $filters)
            ->select('product_id', DB::raw('MAX(product_name) as product_name'), DB::raw('SUM(qty) as qty'), DB::raw('SUM(line_total) as revenue'))
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->when($limit !== null, fn ($q) => $q->limit((int) $limit))
            ->get()
            ->map(fn (object $row): array => [
                'product_id' => (string) $row->product_id,
                'product_name' => (string) $row->product_name,
                'qty' => (int) $row->qty,
                'revenue' => Money::fromDb($row->revenue),
            ])
            ->values()
            ->all();
    }

    /**
     * Produk aktif yang paling sedikit terjual (termasuk yang tidak terjual sama sekali).
     * Satu outlet terpilih: hanya produk yang dijual di outlet itu (ADR 0011).
     *
     * @return list<array{product_id: string, product_name: string, qty: int, revenue: string}>
     */
    public function slowProducts(CarbonImmutable $start, CarbonImmutable $end, int $limit = 10, ?ReportFilters $filters = null): array
    {
        $sold = collect($this->products($start, $end, null, $filters))->keyBy('product_id');
        $outletId = CurrentOutlet::selectedId();

        return Product::query()
            ->active()
            ->when($outletId !== null, fn (Builder $q) => $q->listedAt((string) $outletId))
            ->when($filters !== null && $filters->productIds !== [], fn (Builder $q) => $q->whereIn('id', $filters->productIds ?? []))
            ->when($filters !== null && $filters->categoryIds !== [], fn (Builder $q) => $q->whereIn('category_id', $filters->categoryIds ?? []))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Product $product): array => [
                'product_id' => (string) $product->id,
                'product_name' => (string) $product->name,
                'qty' => (int) ($sold[$product->id]['qty'] ?? 0),
                'revenue' => (string) ($sold[$product->id]['revenue'] ?? '0.00'),
            ])
            ->sortBy([['qty', 'asc'], ['revenue', 'asc']])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Penjualan per metode bayar (pembayaran lunas pada order selesai).
     * Filter metode bayar juga membatasi baris pembayarannya.
     *
     * @return list<array{payment_method_id: string, name: string, category: string, count: int, amount: string}>
     */
    public function paymentMethods(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.status', PaymentStatus::Paid)
            ->whereIn('payments.order_id', $this->completed($start, $end, $filters)->select('orders.id'))
            ->when($filters !== null && $filters->paymentMethodIds !== [], fn (Builder $q) => $q->whereIn('payments.payment_method_id', $filters->paymentMethodIds ?? []))
            ->toBase()
            ->select('payments.payment_method_id', DB::raw('MAX(payment_methods.name) as name'), DB::raw('MAX(payments.category) as category'),
                DB::raw('COUNT(*) as count'), DB::raw('SUM(payments.amount) as amount'))
            ->groupBy('payments.payment_method_id')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row): array => [
                'payment_method_id' => (string) $row->payment_method_id,
                'name' => (string) $row->name,
                'category' => (string) $row->category,
                'count' => (int) $row->count,
                'amount' => Money::fromDb($row->amount),
            ])
            ->values()
            ->all();
    }

    /**
     * Omzet & jumlah transaksi per hari LOKAL outlet (untuk grafik). Hari tanpa transaksi bernilai 0.
     *
     * @return list<array{date: string, revenue: string, order_count: int}>
     */
    public function daily(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        $days = [];
        for ($day = $start->setTimezone($timezone)->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'revenue' => '0.00', 'order_count' => 0];
        }

        // Dikelompokkan di PHP agar konversi zona waktu sama di MySQL & SQLite
        $this->completed($start, $end, $filters)->select(['completed_at', 'grand_total'])->orderBy('completed_at')
            ->each(function (Order $order) use (&$days, $timezone): void {
                $key = $order->completed_at?->setTimezone($timezone)->toDateString();

                if ($key !== null && isset($days[$key])) {
                    $days[$key]['revenue'] = Money::add($days[$key]['revenue'], $order->grand_total);
                    $days[$key]['order_count']++;
                }
            });

        return array_values($days);
    }

    /**
     * Rincian harian untuk tabel riwayat pendapatan (komponen struk + void per hari lokal).
     *
     * @return list<array{date: string, order_count: int, revenue: string, discount_total: string, service_total: string,
     *     tax_total: string, rounding_total: string, void_count: int, void_total: string}>
     */
    public function dailyBreakdown(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        $zero = ['order_count' => 0, 'revenue' => '0.00', 'discount_total' => '0.00', 'service_total' => '0.00',
            'tax_total' => '0.00', 'rounding_total' => '0.00', 'void_count' => 0, 'void_total' => '0.00'];

        $days = [];
        for ($day = $start->setTimezone($timezone)->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), ...$zero];
        }

        $this->completed($start, $end, $filters)
            ->select(['completed_at', 'grand_total', 'discount_total', 'service_total', 'tax_total', 'rounding'])
            ->orderBy('completed_at')
            ->each(function (Order $order) use (&$days, $timezone): void {
                $key = $order->completed_at?->setTimezone($timezone)->toDateString();

                if ($key === null || ! isset($days[$key])) {
                    return;
                }

                $days[$key]['order_count']++;
                $days[$key]['revenue'] = Money::add($days[$key]['revenue'], $order->grand_total);
                $days[$key]['discount_total'] = Money::add($days[$key]['discount_total'], $order->discount_total);
                $days[$key]['service_total'] = Money::add($days[$key]['service_total'], $order->service_total);
                $days[$key]['tax_total'] = Money::add($days[$key]['tax_total'], $order->tax_total);
                $days[$key]['rounding_total'] = Money::add($days[$key]['rounding_total'], $order->rounding);
            });

        $this->voided($start, $end, $filters)->select(['voided_at', 'grand_total'])->orderBy('voided_at')
            ->each(function (Order $order) use (&$days, $timezone): void {
                $key = $order->voided_at?->setTimezone($timezone)->toDateString();

                if ($key !== null && isset($days[$key])) {
                    $days[$key]['void_count']++;
                    $days[$key]['void_total'] = Money::add($days[$key]['void_total'], $order->grand_total);
                }
            });

        return array_values($days);
    }

    /**
     * Omzet & transaksi per jam LOKAL (0–23) sepanjang rentang: jam ramai, atau tren 1 hari.
     *
     * @return list<array{hour: int, revenue: string, order_count: int}>
     */
    public function hourly(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        $hours = [];
        foreach (range(0, 23) as $hour) {
            $hours[$hour] = ['hour' => $hour, 'revenue' => '0.00', 'order_count' => 0];
        }

        $this->completed($start, $end, $filters)->select(['completed_at', 'grand_total'])->orderBy('completed_at')
            ->each(function (Order $order) use (&$hours, $timezone): void {
                $hour = $order->completed_at?->setTimezone($timezone)->hour;

                if ($hour !== null) {
                    $hours[$hour]['revenue'] = Money::add($hours[$hour]['revenue'], $order->grand_total);
                    $hours[$hour]['order_count']++;
                }
            });

        return array_values($hours);
    }

    /**
     * Penjualan per tipe order (dine-in / takeaway); tipe tanpa transaksi tetap muncul bernilai 0.
     *
     * @return list<array{order_type: string, label: string, order_count: int, revenue: string}>
     */
    public function orderTypes(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $rows = $this->completed($start, $end, $filters)
            ->toBase()
            ->select('order_type', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('order_type')
            ->get()
            ->keyBy('order_type');

        return array_map(fn (OrderType $type): array => [
            'order_type' => $type->value,
            'label' => $type->getLabel(),
            'order_count' => (int) ($rows[$type->value]->order_count ?? 0),
            'revenue' => Money::fromDb($rows[$type->value]->revenue ?? null),
        ], OrderType::cases());
    }

    /**
     * Progres target omzet (Q56) outlet yang boleh diakses & aktif yang punya target: hari ini dan bulan
     * berjalan menurut zona waktu masing-masing outlet. Tidak mengikuti filter periode Beranda.
     * month_pace = persen hari bulan ini yang sudah lewat (patokan "sesuai jalur").
     *
     * @return list<array{outlet_id: string, name: string, daily_target: string|null, daily_revenue: string, daily_percent: string|null,
     *     monthly_target: string|null, monthly_revenue: string, monthly_percent: string|null, month_pace: string}>
     */
    public function salesTargets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return Outlet::query()
            ->whereIn('id', CurrentOutlet::ids())
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNotNull('daily_sales_target')->orWhereNotNull('monthly_sales_target'))
            ->orderBy('name')
            ->get()
            ->map(function (Outlet $outlet) use ($now): array {
                $local = $now->setTimezone($outlet->timezone);
                $revenueSince = fn (CarbonImmutable $from): string => Money::fromDb(
                    Order::query()->where('outlet_id', $outlet->id)->where('status', OrderStatus::Completed)
                        ->whereBetween('completed_at', [$from->utc(), $now->utc()])->toBase()->sum('grand_total'),
                );
                $percent = fn (string $revenue, ?string $target): ?string => $target !== null && Money::isPositive($target)
                    ? Money::round(bcdiv(bcmul($revenue, '100', 4), $target, 4), 1)
                    : null;

                $daily = $revenueSince($local->startOfDay());
                $monthly = $revenueSince($local->startOfMonth());

                return [
                    'outlet_id' => (string) $outlet->id,
                    'name' => $outlet->name,
                    'daily_target' => $outlet->daily_sales_target,
                    'daily_revenue' => $daily,
                    'daily_percent' => $percent($daily, $outlet->daily_sales_target),
                    'monthly_target' => $outlet->monthly_sales_target,
                    'monthly_revenue' => $monthly,
                    'monthly_percent' => $percent($monthly, $outlet->monthly_sales_target),
                    'month_pace' => Money::round(bcdiv(bcmul((string) $local->day, '100', 4), (string) $local->daysInMonth, 4), 1),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Perbandingan antar-outlet yang boleh diakses (urut omzet terbesar).
     *
     * @return list<array{outlet_id: string, name: string, order_count: int, revenue: string, average: string, void_count: int, void_total: string}>
     */
    public function outlets(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $sales = $this->completed($start, $end, $filters)->toBase()
            ->select('outlet_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'))
            ->groupBy('outlet_id')->get()->keyBy('outlet_id');

        $voids = $this->voided($start, $end, $filters)->toBase()
            ->select('outlet_id', DB::raw('COUNT(*) as void_count'), DB::raw('SUM(grand_total) as void_total'))
            ->groupBy('outlet_id')->get()->keyBy('outlet_id');

        return Outlet::query()
            ->whereIn('id', CurrentOutlet::ids())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Outlet $outlet) use ($sales, $voids): array {
                $count = (int) ($sales[$outlet->id]->order_count ?? 0);
                $revenue = Money::fromDb($sales[$outlet->id]->revenue ?? null);

                return [
                    'outlet_id' => (string) $outlet->id,
                    'name' => $outlet->name,
                    'order_count' => $count,
                    'revenue' => $revenue,
                    'average' => Money::div($revenue, $count),
                    'void_count' => (int) ($voids[$outlet->id]->void_count ?? 0),
                    'void_total' => Money::fromDb($voids[$outlet->id]->void_total ?? null),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['revenue'])
            ->values()
            ->all();
    }

    /**
     * Temuan audit periode ini: void, diskon dengan approval PIN, dan shift bermasalah
     * (selisih kas ≠ 0 atau ditutup paksa). Tiap daftar dibatasi $limit baris terbaru,
     * jumlah total ada di *_count.
     *
     * @return array{
     *     void_count: int, void_total: string,
     *     voids: list<array{order_id: string, order_number: string, outlet: string, grand_total: string, reason: string|null, voided_by: string|null, approved_by: string|null, voided_at: string|null}>,
     *     discount_count: int, discount_total: string,
     *     discounts: list<array{order_id: string, order_number: string, outlet: string, discount_total: string, grand_total: string, cashier: string|null, approved_by: string|null, completed_at: string|null}>,
     *     shift_issue_count: int, shift_difference_total: string,
     *     shifts: list<array{shift_id: string, outlet: string, cashier: string|null, closed_by: string|null, status: string, expected_cash: string, actual_cash: string, difference: string, note: string|null, closed_at: string|null}>
     * }
     */
    public function audit(CarbonImmutable $start, CarbonImmutable $end, int $limit = 10, ?ReportFilters $filters = null): array
    {
        $voidQuery = $this->voided($start, $end, $filters);
        $voidTotals = (clone $voidQuery)->toBase()->selectRaw('COUNT(*) as c, SUM(grand_total) as t')->first();
        $voids = $voidQuery->latest('voided_at')->limit($limit)
            ->get(['id', 'order_number', 'outlet_id', 'grand_total', 'void_reason', 'voided_by', 'void_approved_by', 'voided_at']);

        $discountQuery = $this->completed($start, $end, $filters)->whereNotNull('approved_by')->where('discount_total', '>', 0);
        $discountTotals = (clone $discountQuery)->toBase()->selectRaw('COUNT(*) as c, SUM(discount_total) as t')->first();
        $discounts = $discountQuery->latest('completed_at')->limit($limit)
            ->get(['id', 'order_number', 'outlet_id', 'discount_total', 'grand_total', 'user_id', 'approved_by', 'completed_at']);

        // Shift tidak punya tipe order/metode bayar: hanya filter kasir yang relevan
        $shiftQuery = CurrentOutlet::scope(Shift::query())
            ->whereIn('status', [ShiftStatus::Closed, ShiftStatus::ForceClosed])
            ->whereBetween('closed_at', [$start, $end])
            ->where(fn (Builder $q) => $q->where('difference', '!=', 0)->orWhere('status', ShiftStatus::ForceClosed))
            ->when($filters !== null && $filters->userIds !== [], fn (Builder $q) => $q->whereIn('opened_by', $filters->userIds ?? []));
        $shiftTotals = (clone $shiftQuery)->toBase()->selectRaw('COUNT(*) as c, SUM(difference) as t')->first();
        $shifts = $shiftQuery->latest('closed_at')->limit($limit)
            ->get(['id', 'outlet_id', 'opened_by', 'closed_by', 'status', 'expected_cash', 'actual_cash', 'difference', 'close_note', 'closed_at']);

        // Nama user & outlet dalam 2 query (tanpa N+1)
        $users = User::query()->whereIn('id', collect([
            ...$voids->pluck('voided_by'), ...$voids->pluck('void_approved_by'),
            ...$discounts->pluck('user_id'), ...$discounts->pluck('approved_by'),
            ...$shifts->pluck('opened_by'), ...$shifts->pluck('closed_by'),
        ])->filter()->unique()->values())->pluck('name', 'id');
        $outlets = Outlet::query()->whereIn('id', CurrentOutlet::ids())->pluck('name', 'id');

        $name = fn (?string $id): ?string => $id !== null ? ($users[$id] ?? null) : null;

        return [
            'void_count' => (int) ($voidTotals->c ?? 0),
            'void_total' => Money::fromDb($voidTotals->t ?? null),
            'voids' => $voids->map(fn (Order $o): array => [
                'order_id' => (string) $o->id,
                'order_number' => $o->order_number,
                'outlet' => (string) ($outlets[$o->outlet_id] ?? ''),
                'grand_total' => $o->grand_total,
                'reason' => $o->void_reason,
                'voided_by' => $name($o->voided_by),
                'approved_by' => $name($o->void_approved_by),
                'voided_at' => $o->voided_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'discount_count' => (int) ($discountTotals->c ?? 0),
            'discount_total' => Money::fromDb($discountTotals->t ?? null),
            'discounts' => $discounts->map(fn (Order $o): array => [
                'order_id' => (string) $o->id,
                'order_number' => $o->order_number,
                'outlet' => (string) ($outlets[$o->outlet_id] ?? ''),
                'discount_total' => $o->discount_total,
                'grand_total' => $o->grand_total,
                'cashier' => $name($o->user_id),
                'approved_by' => $name($o->approved_by),
                'completed_at' => $o->completed_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'shift_issue_count' => (int) ($shiftTotals->c ?? 0),
            'shift_difference_total' => Money::fromDb($shiftTotals->t ?? null),
            'shifts' => $shifts->map(fn (Shift $s): array => [
                'shift_id' => (string) $s->id,
                'outlet' => (string) ($outlets[$s->outlet_id] ?? ''),
                'cashier' => $name($s->opened_by),
                'closed_by' => $name($s->closed_by),
                'status' => $s->status->value,
                'expected_cash' => Money::fromDb($s->expected_cash),
                'actual_cash' => Money::fromDb($s->actual_cash),
                'difference' => Money::fromDb($s->difference),
                'note' => $s->close_note,
                'closed_at' => $s->closed_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }

    /**
     * Tren omzet per granularitas: hour (0–23, untuk 1 hari), day, week (Senin, ISO), month.
     * Minggu & bulan dikelompokkan dari seri harian lokal outlet agar zona waktu konsisten
     * di MySQL/MariaDB/SQLite. key = jam / tanggal awal bucket (Y-m-d).
     *
     * @return list<array{key: string, label: string, from: string, to: string, revenue: string, order_count: int}>
     */
    public function trend(CarbonImmutable $start, CarbonImmutable $end, string $timezone, string $granularity, ?ReportFilters $filters = null): array
    {
        if ($granularity === 'hour') {
            $date = $start->setTimezone($timezone)->toDateString();

            return array_map(fn (array $h): array => [
                'key' => sprintf('%02d', $h['hour']),
                'label' => sprintf('%02d.00', $h['hour']),
                'from' => $date,
                'to' => $date,
                'revenue' => $h['revenue'],
                'order_count' => $h['order_count'],
            ], $this->hourly($start, $end, $timezone, $filters));
        }

        $buckets = [];
        foreach ($this->daily($start, $end, $timezone, $filters) as $day) {
            $date = CarbonImmutable::parse($day['date'])->locale('id');
            [$key, $label, $bucketEnd] = match ($granularity) {
                'week' => [
                    $date->startOfWeek(CarbonInterface::MONDAY)->toDateString(),
                    $date->startOfWeek(CarbonInterface::MONDAY)->translatedFormat('j M'),
                    $date->endOfWeek(CarbonInterface::SUNDAY)->toDateString(),
                ],
                'month' => [$date->startOfMonth()->toDateString(), $date->translatedFormat('M Y'), $date->endOfMonth()->toDateString()],
                default => [$day['date'], $date->translatedFormat('D j/n'), $day['date']],
            };

            $buckets[$key] ??= ['key' => $key, 'label' => $label, 'from' => $day['date'], 'to' => $bucketEnd, 'revenue' => '0.00', 'order_count' => 0];
            $buckets[$key]['revenue'] = Money::add($buckets[$key]['revenue'], $day['revenue']);
            $buckets[$key]['order_count'] += $day['order_count'];
            // Bucket terpotong rentang laporan (minggu/bulan pertama & terakhir)
            $buckets[$key]['to'] = min($bucketEnd, $end->setTimezone($timezone)->toDateString());
        }

        return array_values($buckets);
    }

    /**
     * Penjualan per kategori dari snapshot order_items.category_name (Q51), urut omzet terbesar.
     *
     * @return list<array{category_name: string, product_count: int, qty: int, revenue: string}>
     */
    public function categories(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return $this->items($start, $end, $filters)
            ->select('category_name', DB::raw('COUNT(DISTINCT product_id) as product_count'), DB::raw('SUM(qty) as qty'), DB::raw('SUM(line_total) as revenue'))
            ->groupBy('category_name')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn (object $row): array => [
                'category_name' => (string) $row->category_name,
                'product_count' => (int) $row->product_count,
                'qty' => (int) $row->qty,
                'revenue' => Money::fromDb($row->revenue),
            ])
            ->values()
            ->all();
    }

    /**
     * Laba kotor per produk (R1, Q53) dari snapshot harga modal `order_items.unit_cost`.
     *
     * Penjualan bersih per item = line_total × (grand_total − pajak − service − pembulatan) / subtotal order:
     * diskon order dialokasikan proporsional dan pajak (termasuk harga-termasuk-pajak) dikeluarkan, sehingga
     * total = net_sales di summary(). HPP = qty × unit_cost; item tanpa harga modal tidak dihitung dalam
     * laba/margin (dilaporkan terpisah) agar margin tidak terlihat 100%. Harga opsi belum punya modal.
     *
     * @return array{
     *     products: list<array{product_id: string, product_name: string, category_name: string, qty: int, net_sales: string, cogs: string, gross_profit: string|null, margin: string|null, uncosted_qty: int}>,
     *     totals: array{net_sales: string, costed_sales: string, cogs: string, gross_profit: string, margin: string|null, uncosted_qty: int, uncosted_sales: string}
     * }
     */
    public function profit(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $net = '(order_items.line_total * (orders.grand_total - orders.tax_total - orders.service_total - orders.rounding) / NULLIF(orders.subtotal, 0))';

        $rows = $this->items($start, $end, $filters)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->select(
                'order_items.product_id',
                DB::raw('MAX(order_items.product_name) as product_name'),
                DB::raw('MAX(order_items.category_name) as category_name'),
                DB::raw('SUM(order_items.qty) as qty'),
                DB::raw("ROUND(COALESCE(SUM({$net}), 0), 2) as net_sales"),
                DB::raw('ROUND(COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL THEN 0 ELSE order_items.qty * order_items.unit_cost END), 0), 2) as cogs'),
                DB::raw("ROUND(COALESCE(SUM(CASE WHEN order_items.unit_cost IS NULL THEN {$net} ELSE 0 END), 0), 2) as uncosted_sales"),
                DB::raw('SUM(CASE WHEN order_items.unit_cost IS NULL THEN order_items.qty ELSE 0 END) as uncosted_qty'),
            )
            ->groupBy('order_items.product_id')
            ->get();

        $products = $rows->map(function (object $row): array {
            $netSales = Money::fromDb($row->net_sales);
            $costedSales = Money::sub($netSales, Money::fromDb($row->uncosted_sales));
            $cogs = Money::fromDb($row->cogs);
            $costed = (int) $row->qty > (int) $row->uncosted_qty;
            $profit = $costed ? Money::sub($costedSales, $cogs) : null;

            return [
                'product_id' => (string) $row->product_id,
                'product_name' => (string) $row->product_name,
                'category_name' => (string) $row->category_name,
                'qty' => (int) $row->qty,
                'net_sales' => $netSales,
                'cogs' => $cogs,
                'gross_profit' => $profit,
                'margin' => $profit !== null ? self::marginPercent($profit, $costedSales) : null,
                'uncosted_qty' => (int) $row->uncosted_qty,
                '_uncosted_sales' => Money::fromDb($row->uncosted_sales),
            ];
        })->sortByDesc(fn (array $r): float => (float) ($r['gross_profit'] ?? '-1e15'))->values();

        $netSales = Money::add('0', ...$products->pluck('net_sales')->all());
        $uncostedSales = Money::add('0', ...$products->pluck('_uncosted_sales')->all());
        $costedSales = Money::sub($netSales, $uncostedSales);
        $cogs = Money::add('0', ...$products->pluck('cogs')->all());
        $profit = Money::sub($costedSales, $cogs);

        return [
            'products' => $products->map(fn (array $r): array => collect($r)->except('_uncosted_sales')->all())->all(),
            'totals' => [
                'net_sales' => $netSales,
                'costed_sales' => $costedSales,
                'cogs' => $cogs,
                'gross_profit' => $profit,
                'margin' => Money::isPositive($costedSales) ? self::marginPercent($profit, $costedSales) : null,
                'uncosted_qty' => (int) $products->sum('uncosted_qty'),
                'uncosted_sales' => $uncostedSales,
            ],
        ];
    }

    /** Persen 1 desimal ("42.5"); null bila penjualan 0. */
    private static function marginPercent(string $profit, string $sales): ?string
    {
        return Money::isPositive($sales) ? Money::round(bcdiv(bcmul($profit, '100', 6), $sales, 6), 1) : null;
    }

    /**
     * Laporan member (ADR 0013, M7): member vs non-member, member baru & berulang, member teratas, poin, utang poin,
     * voucher, dan kartu stempel. Transaksi & poin mengikuti outlet/filter; member baru mengikuti outlet daftar;
     * utang poin = saldo positif saat ini × nilai 1 poin (seluruh bisnis, bukan per periode).
     *
     * @return array{
     *     summary: array{member_orders: int, member_revenue: string, guest_orders: int, guest_revenue: string, member_share: string|null,
     *         active_members: int, repeat_members: int, new_members: int, member_discount: string, points_earned: int, points_redeemed: int,
     *         points_expired: int, points_liability: int, points_liability_value: string, voucher_uses: int, voucher_discount: string,
     *         stamp_free_count: int, stamp_free_total: string},
     *     top_members: list<array{member_id: string, member_name: string, tier: string|null, order_count: int, revenue: string, points_earned: int}>,
     *     vouchers: list<array{voucher_code: string, uses: int, discount: string}>,
     *     tiers: list<array{tier: string, member_count: int}>
     * }
     */
    public function members(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $orders = fn (): Builder => $this->completed($start, $end, $filters);

        $split = $orders()->toBase()->selectRaw('CASE WHEN member_id IS NULL THEN 0 ELSE 1 END AS is_member, COUNT(*) AS order_count, SUM(grand_total) AS revenue')
            ->groupByRaw('CASE WHEN member_id IS NULL THEN 0 ELSE 1 END')->get()->keyBy('is_member');
        $memberRevenue = Money::fromDb($split[1]->revenue ?? null);
        $guestRevenue = Money::fromDb($split[0]->revenue ?? null);
        $allRevenue = Money::add($memberRevenue, $guestRevenue);

        $visits = $orders()->toBase()->whereNotNull('member_id')->groupBy('member_id')->selectRaw('member_id, COUNT(*) AS visits')->pluck('visits', 'member_id');
        $benefits = $orders()->toBase()->selectRaw('SUM(member_discount) AS member_discount, SUM(voucher_discount) AS voucher_discount,
            SUM(CASE WHEN voucher_id IS NULL THEN 0 ELSE 1 END) AS voucher_uses, SUM(stamp_free_total) AS stamp_free_total')->first();

        $ledger = fn (PointEntryType $type): int => (int) CurrentOutlet::scope(MemberPointEntry::query())->where('type', $type)
            ->whereIn('order_id', $orders()->select('id'))->sum('points');
        $setting = MemberSetting::current();
        $liability = (int) Member::query()->where('points_balance', '>', 0)->sum('points_balance');

        $outletIds = CurrentOutlet::ids();
        $newMembers = Member::query()->whereBetween('created_at', [$start, $end])
            ->where(fn ($q) => $q->whereIn('joined_outlet_id', $outletIds)->when(CurrentOutlet::selectedId() === null, fn ($q) => $q->orWhereNull('joined_outlet_id')))
            ->count();

        $stampFreeCount = 0;
        foreach ($orders()->whereNotNull('stamp_redemptions')->pluck('stamp_redemptions') as $redemptions) {
            $stampFreeCount += is_array($redemptions) ? count($redemptions) : 0;
        }

        $top = $orders()->toBase()->whereNotNull('orders.member_id')
            ->leftJoin('members', 'members.id', '=', 'orders.member_id')
            ->leftJoin('member_tiers', 'member_tiers.id', '=', 'members.tier_id')
            ->groupBy('orders.member_id')
            ->selectRaw('orders.member_id, MAX(members.name) AS member_name, MAX(member_tiers.name) AS tier, COUNT(*) AS order_count,
                SUM(orders.grand_total) AS revenue, SUM(orders.points_earned) AS points_earned')
            ->orderByDesc('revenue')->limit(20)->get()
            ->map(fn (object $r): array => [
                'member_id' => (string) $r->member_id,
                'member_name' => (string) $r->member_name,
                'tier' => $r->tier !== null ? (string) $r->tier : null,
                'order_count' => (int) $r->order_count,
                'revenue' => Money::fromDb($r->revenue),
                'points_earned' => (int) $r->points_earned,
            ])->all();

        return [
            'summary' => [
                'member_orders' => (int) ($split[1]->order_count ?? 0),
                'member_revenue' => $memberRevenue,
                'guest_orders' => (int) ($split[0]->order_count ?? 0),
                'guest_revenue' => $guestRevenue,
                'member_share' => Money::isPositive($allRevenue) ? Money::round(bcdiv(bcmul($memberRevenue, '100', 6), $allRevenue, 6), 1) : null,
                'active_members' => $visits->count(),
                'repeat_members' => $visits->filter(fn (mixed $v): bool => (int) $v >= 2)->count(),
                'new_members' => $newMembers,
                'member_discount' => Money::fromDb($benefits->member_discount ?? null),
                'points_earned' => $ledger(PointEntryType::Earn),
                'points_redeemed' => -$ledger(PointEntryType::Redeem),
                'points_expired' => -(int) MemberPointEntry::query()->where('type', PointEntryType::Expire)->whereBetween('created_at', [$start, $end])->sum('points'),
                'points_liability' => $liability,
                'points_liability_value' => Money::mul((string) $setting->redeem_value, $liability),
                'voucher_uses' => (int) ($benefits->voucher_uses ?? 0),
                'voucher_discount' => Money::fromDb($benefits->voucher_discount ?? null),
                'stamp_free_count' => $stampFreeCount,
                'stamp_free_total' => Money::fromDb($benefits->stamp_free_total ?? null),
            ],
            'top_members' => $top,
            'vouchers' => $orders()->toBase()->whereNotNull('voucher_id')->groupBy('voucher_code')
                ->selectRaw('voucher_code, COUNT(*) AS uses, SUM(voucher_discount) AS discount')->orderByDesc('uses')->get()
                ->map(fn (object $r): array => ['voucher_code' => (string) $r->voucher_code, 'uses' => (int) $r->uses, 'discount' => Money::fromDb($r->discount)])->all(),
            'tiers' => Member::query()->whereNull('anonymized_at')->leftJoin('member_tiers', 'member_tiers.id', '=', 'members.tier_id')
                ->groupBy('member_tiers.name')->selectRaw('member_tiers.name AS tier, COUNT(*) AS member_count')->orderByDesc('member_count')->toBase()->get()
                ->map(fn (object $r): array => ['tier' => $r->tier !== null ? (string) $r->tier : 'Tanpa tier', 'member_count' => (int) $r->member_count])->all(),
        ];
    }

    /**
     * Opsi / add-on terlaris: dipilih berapa kali (× qty item) dan tambahan omzet (price_delta × qty).
     *
     * @return list<array{option_id: string, option_name: string, qty: int, revenue: string}>
     */
    public function options(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        return DB::table('order_item_options')
            ->join('order_items', 'order_items.id', '=', 'order_item_options.order_item_id')
            ->whereIn('order_items.order_id', $this->completed($start, $end, $filters)->select('orders.id'))
            ->when($filters !== null, fn ($q) => $filters?->applyToItems($q))
            ->select('order_item_options.option_id', DB::raw('MAX(order_item_options.option_name) as option_name'),
                DB::raw('SUM(order_items.qty) as qty'), DB::raw('SUM(order_item_options.price_delta * order_items.qty) as revenue'))
            ->groupBy('order_item_options.option_id')
            ->orderByDesc('qty')
            ->get()
            ->map(fn (object $row): array => [
                'option_id' => (string) $row->option_id,
                'option_name' => (string) $row->option_name,
                'qty' => (int) $row->qty,
                'revenue' => Money::fromDb($row->revenue),
            ])
            ->values()
            ->all();
    }

    /**
     * Kinerja per kasir (pembuat order): transaksi, omzet, rata-rata, diskon diberikan, void.
     *
     * @return list<array{user_id: string, name: string, order_count: int, revenue: string, average: string, discount_total: string, void_count: int, void_total: string}>
     */
    public function employees(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $sales = $this->completed($start, $end, $filters)->toBase()
            ->select('user_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as revenue'), DB::raw('SUM(discount_total) as discount_total'))
            ->groupBy('user_id')->get()->keyBy('user_id');
        $voids = $this->voided($start, $end, $filters)->toBase()
            ->select('user_id', DB::raw('COUNT(*) as void_count'), DB::raw('SUM(grand_total) as void_total'))
            ->groupBy('user_id')->get()->keyBy('user_id');

        $names = User::query()->whereIn('id', $sales->keys()->merge($voids->keys())->unique()->values())->pluck('name', 'id');

        return $sales->keys()->merge($voids->keys())->unique()
            ->map(function (string $id) use ($sales, $voids, $names): array {
                $count = (int) ($sales[$id]->order_count ?? 0);
                $revenue = Money::fromDb($sales[$id]->revenue ?? null);

                return [
                    'user_id' => $id,
                    'name' => (string) ($names[$id] ?? '–'),
                    'order_count' => $count,
                    'revenue' => $revenue,
                    'average' => Money::div($revenue, $count),
                    'discount_total' => Money::fromDb($sales[$id]->discount_total ?? null),
                    'void_count' => (int) ($voids[$id]->void_count ?? 0),
                    'void_total' => Money::fromDb($voids[$id]->void_total ?? null),
                ];
            })
            ->sortByDesc(fn (array $row): float => (float) $row['revenue'])
            ->values()
            ->all();
    }

    /**
     * Rekap per shift yang DIBUKA pada rentang: kasir, waktu, kas awal, penjualan & tunai,
     * kas seharusnya vs aktual, selisih. Shift berjalan: kas seharusnya dihitung saat ini.
     *
     * @return list<array{shift_id: string, outlet: string, cashier: string, status: string, opened_at: string, closed_at: string|null,
     *     order_count: int, sales_total: string, opening_cash: string, cash_sales: string, expected_cash: string, actual_cash: string|null, difference: string|null}>
     */
    public function shifts(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null, ?string $openedBy = null): array
    {
        $shifts = $this->shiftQuery($start, $end, $filters, $openedBy)->orderByDesc('opened_at')
            ->get(['id', 'outlet_id', 'opened_by', 'status', 'opened_at', 'closed_at', 'opening_cash', 'expected_cash', 'actual_cash', 'difference']);

        $ids = $shifts->pluck('id');
        $sales = Order::query()->whereIn('shift_id', $ids)->where('status', OrderStatus::Completed)->toBase()
            ->select('shift_id', DB::raw('COUNT(*) as order_count'), DB::raw('SUM(grand_total) as sales_total'))
            ->groupBy('shift_id')->get()->keyBy('shift_id');
        // Σ amount tunai sudah bersih dari kembalian (sama dengan ShiftSummary)
        $cash = Payment::query()
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('orders.shift_id', $ids)
            ->where('orders.status', OrderStatus::Completed)
            ->where('payments.status', PaymentStatus::Paid)
            ->where('payments.category', PaymentCategory::Cash)
            ->toBase()
            ->select('orders.shift_id', DB::raw('SUM(payments.amount) as cash_sales'))
            ->groupBy('orders.shift_id')->get()->keyBy('shift_id');

        $names = User::query()->whereIn('id', $shifts->pluck('opened_by')->unique()->values())->pluck('name', 'id');
        $outlets = Outlet::query()->whereIn('id', CurrentOutlet::ids())->pluck('name', 'id');

        return $shifts->map(function (Shift $shift) use ($sales, $cash, $names, $outlets): array {
            $cashSales = Money::fromDb($cash[$shift->id]->cash_sales ?? null);
            $opening = Money::fromDb($shift->opening_cash);

            return [
                'shift_id' => (string) $shift->id,
                'outlet' => (string) ($outlets[$shift->outlet_id] ?? ''),
                'cashier' => (string) ($names[$shift->opened_by] ?? '–'),
                'status' => $shift->status->value,
                'opened_at' => $shift->opened_at->toIso8601ZuluString(),
                'closed_at' => $shift->closed_at?->toIso8601ZuluString(),
                'order_count' => (int) ($sales[$shift->id]->order_count ?? 0),
                'sales_total' => Money::fromDb($sales[$shift->id]->sales_total ?? null),
                'opening_cash' => $opening,
                'cash_sales' => $cashSales,
                'expected_cash' => $shift->expected_cash !== null ? Money::fromDb($shift->expected_cash) : Money::add($opening, $cashSales),
                'actual_cash' => $shift->actual_cash !== null ? Money::fromDb($shift->actual_cash) : null,
                'difference' => $shift->difference !== null ? Money::fromDb($shift->difference) : null,
            ];
        })->values()->all();
    }

    /**
     * Jumlah transaksi per hari-dalam-minggu (1 = Senin … 7 = Minggu, ISO) × jam lokal (0–23).
     *
     * @return array<int, array<int, int>>
     */
    public function heatmap(CarbonImmutable $start, CarbonImmutable $end, string $timezone, ?ReportFilters $filters = null): array
    {
        $grid = [];
        foreach (range(1, 7) as $day) {
            $grid[$day] = array_fill(0, 24, 0);
        }

        $this->completed($start, $end, $filters)->select(['completed_at'])->orderBy('completed_at')
            ->each(function (Order $order) use (&$grid, $timezone): void {
                $local = $order->completed_at?->setTimezone($timezone);

                if ($local !== null) {
                    $grid[$local->dayOfWeekIso][$local->hour]++;
                }
            });

        return $grid;
    }

    /**
     * Pergerakan stok per produk pada rentang (penjualan, pengembalian void, penyesuaian) +
     * stok saat ini. Hanya produk yang dilacak stoknya / punya pergerakan.
     *
     * @return list<array{product_id: string, product_name: string, sold: int, void_return: int, adjustment: int, net_change: int, stock: int|null, min_stock: int|null, is_low: bool}>
     */
    public function stock(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $movements = StockMovement::query()
            ->whereIn('outlet_id', CurrentOutlet::ids())
            ->whereBetween('created_at', [$start, $end])
            ->when($filters !== null && $filters->productIds !== [], fn (Builder $q) => $q->whereIn('product_id', $filters->productIds ?? []))
            ->toBase()
            ->select('product_id', 'type', DB::raw('SUM(qty_change) as qty'))
            ->groupBy('product_id', 'type')
            ->get()
            ->groupBy('product_id');

        $stocks = ProductStock::query()->whereIn('outlet_id', CurrentOutlet::ids())->toBase()
            ->select('product_id', DB::raw('SUM(stock_qty) as stock'), DB::raw('SUM(min_stock) as min_stock'))
            ->groupBy('product_id')->get()->keyBy('product_id');

        return Product::query()
            ->withTrashed()
            ->where(fn (Builder $q) => $q->where('track_stock', true)->orWhereIn('id', $movements->keys()))
            ->when($filters !== null && $filters->productIds !== [], fn (Builder $q) => $q->whereIn('id', $filters->productIds ?? []))
            ->when($filters !== null && $filters->categoryIds !== [], fn (Builder $q) => $q->whereIn('category_id', $filters->categoryIds ?? []))
            ->orderBy('name')
            ->get(['id', 'name', 'track_stock'])
            ->map(function (Product $product) use ($movements, $stocks): array {
                $byType = collect($movements[$product->id] ?? [])->mapWithKeys(fn (object $m): array => [(string) $m->type => (int) $m->qty]);
                $stock = $product->track_stock && isset($stocks[$product->id]) ? (int) $stocks[$product->id]->stock : ($product->track_stock ? 0 : null);
                $min = $product->track_stock && isset($stocks[$product->id]) ? (int) $stocks[$product->id]->min_stock : null;

                return [
                    'product_id' => (string) $product->id,
                    'product_name' => $product->name,
                    // Penjualan tercatat negatif di stock_movements; ditampilkan positif
                    'sold' => abs((int) ($byType[StockMovementType::Sale->value] ?? 0)),
                    'void_return' => (int) ($byType[StockMovementType::VoidReturn->value] ?? 0),
                    'adjustment' => (int) ($byType[StockMovementType::Adjustment->value] ?? 0),
                    'net_change' => (int) $byType->sum(),
                    'stock' => $stock,
                    'min_stock' => $min,
                    // Sama dengan scopeOutOfStock / scopeLowStock: habis (≤ 0) atau ≤ stok minimum
                    'is_low' => $stock !== null && ($stock <= 0 || ($min !== null && $stock <= $min)),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Query order void (untuk tabel Diskon & void berpaginasi).
     *
     * @return Builder<Order>
     */
    public function voidQuery(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): Builder
    {
        return $this->voided($start, $end, $filters);
    }

    /**
     * Query order selesai yang diskonnya disetujui PIN (approved_by terisi).
     *
     * @return Builder<Order>
     */
    public function approvedDiscountQuery(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): Builder
    {
        return $this->completed($start, $end, $filters)->whereNotNull('approved_by')->where('discount_total', '>', 0);
    }

    /**
     * Shift yang dibuka pada rentang (outlet aktif); filter kasir & shift ikut diterapkan.
     *
     * @return Builder<Shift>
     */
    public function shiftQuery(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null, ?string $openedBy = null): Builder
    {
        return CurrentOutlet::scope(Shift::query())
            ->whereBetween('opened_at', [$start, $end])
            ->when($openedBy !== null, fn (Builder $q) => $q->where('opened_by', $openedBy))
            ->when($filters !== null && $filters->userIds !== [], fn (Builder $q) => $q->whereIn('opened_by', $filters->userIds ?? []))
            ->when($filters !== null && $filters->shiftIds !== [], fn (Builder $q) => $q->whereIn('id', $filters->shiftIds ?? []));
    }

    /**
     * Perubahan persen (1 desimal, string) dari $before ke $now; null bila pembanding 0.
     */
    public static function change(string $now, string $before): ?string
    {
        if (Money::compare($before, '0') === 0) {
            return null;
        }

        return Money::round(bcdiv(bcmul(Money::sub($now, $before), '100', 6), $before, 6), 1);
    }

    /**
     * Baris order_items dari order selesai (filter level order + item).
     */
    private function items(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): \Illuminate\Database\Query\Builder
    {
        $items = OrderItem::query()->whereIn('order_id', $this->completed($start, $end, $filters)->select('id'))->toBase();

        return $filters !== null ? $filters->applyToItems($items) : $items;
    }

    /** @return Builder<Order> */
    private function completed(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): Builder
    {
        $query = CurrentOutlet::scope(Order::query())
            ->where('status', OrderStatus::Completed)
            ->whereBetween('completed_at', [$start, $end]);

        return $filters !== null ? $filters->apply($query) : $query;
    }

    /** @return Builder<Order> */
    private function voided(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): Builder
    {
        $query = CurrentOutlet::scope(Order::query())
            ->where('status', OrderStatus::Voided)
            ->whereBetween('voided_at', [$start, $end]);

        return $filters !== null ? $filters->apply($query) : $query;
    }
}
