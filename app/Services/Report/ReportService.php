<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\ShiftStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
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
final class ReportService
{
    /**
     * @return array{order_count: int, revenue: string, average: string, items_sold: int, discount_total: string,
     *     service_total: string, tax_total: string, rounding_total: string, void_count: int, void_total: string}
     */
    public function summary(CarbonImmutable $start, CarbonImmutable $end, ?ReportFilters $filters = null): array
    {
        $totals = $this->completed($start, $end, $filters)
            ->toBase()
            ->selectRaw('COUNT(*) as order_count, SUM(grand_total) as revenue, SUM(discount_total) as discount_total,
                SUM(service_total) as service_total, SUM(tax_total) as tax_total, SUM(rounding) as rounding_total')
            ->first();

        $voids = $this->voided($start, $end, $filters)
            ->toBase()
            ->selectRaw('COUNT(*) as void_count, SUM(grand_total) as void_total')
            ->first();

        $orderCount = (int) ($totals->order_count ?? 0);
        $revenue = Money::fromDb($totals->revenue ?? null);

        return [
            'order_count' => $orderCount,
            'revenue' => $revenue,
            'average' => Money::div($revenue, $orderCount),
            'items_sold' => (int) OrderItem::query()->whereIn('order_id', $this->completed($start, $end, $filters)->select('id'))->sum('qty'),
            'discount_total' => Money::fromDb($totals->discount_total ?? null),
            'service_total' => Money::fromDb($totals->service_total ?? null),
            'tax_total' => Money::fromDb($totals->tax_total ?? null),
            'rounding_total' => Money::fromDb($totals->rounding_total ?? null),
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
        return OrderItem::query()
            ->whereIn('order_id', $this->completed($start, $end, $filters)->select('id'))
            ->toBase()
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
     * Perubahan persen (1 desimal, string) dari $before ke $now; null bila pembanding 0.
     */
    public static function change(string $now, string $before): ?string
    {
        if (Money::compare($before, '0') === 0) {
            return null;
        }

        return Money::round(bcdiv(bcmul(Money::sub($now, $before), '100', 6), $before, 6), 1);
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
