<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya sumber angka laporan (API /reports, widget beranda, halaman Laporan, Excel).
 * Hanya baca; isolasi tenant otomatis lewat TenantScope.
 *
 * - Omzet diakui saat order SELESAI (completed_at), bukan saat dibuat.
 * - Order void tidak masuk omzet; dilaporkan terpisah berdasarkan voided_at.
 * - Rentang waktu dalam UTC (konversi dari tanggal lokal outlet lewat CurrentOutlet::utcRange).
 */
final class ReportService
{
    /**
     * @return array{order_count: int, revenue: string, average: string, items_sold: int, discount_total: string,
     *     service_total: string, tax_total: string, rounding_total: string, void_count: int, void_total: string}
     */
    public function summary(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = $this->completed($start, $end)
            ->toBase()
            ->selectRaw('COUNT(*) as order_count, SUM(grand_total) as revenue, SUM(discount_total) as discount_total,
                SUM(service_total) as service_total, SUM(tax_total) as tax_total, SUM(rounding) as rounding_total')
            ->first();

        $voids = Order::query()
            ->where('status', OrderStatus::Voided)
            ->whereBetween('voided_at', [$start, $end])
            ->toBase()
            ->selectRaw('COUNT(*) as void_count, SUM(grand_total) as void_total')
            ->first();

        $orderCount = (int) ($totals->order_count ?? 0);
        $revenue = Money::fromDb($totals->revenue ?? null);

        return [
            'order_count' => $orderCount,
            'revenue' => $revenue,
            'average' => Money::div($revenue, $orderCount),
            'items_sold' => (int) OrderItem::query()->whereIn('order_id', $this->completed($start, $end)->select('id'))->sum('qty'),
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
    public function products(CarbonImmutable $start, CarbonImmutable $end, ?int $limit = null): array
    {
        return OrderItem::query()
            ->whereIn('order_id', $this->completed($start, $end)->select('id'))
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
     * Penjualan per metode bayar (pembayaran lunas pada order selesai).
     *
     * @return list<array{payment_method_id: string, name: string, category: string, count: int, amount: string}>
     */
    public function paymentMethods(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Payment::query()
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->where('payments.status', PaymentStatus::Paid)
            ->whereIn('payments.order_id', $this->completed($start, $end)->select('orders.id'))
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
    public function daily(CarbonImmutable $start, CarbonImmutable $end, string $timezone): array
    {
        $days = [];
        for ($day = $start->setTimezone($timezone)->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'revenue' => '0.00', 'order_count' => 0];
        }

        // Dikelompokkan di PHP agar konversi zona waktu sama di MySQL & SQLite
        $this->completed($start, $end)->select(['completed_at', 'grand_total'])->orderBy('completed_at')
            ->each(function (Order $order) use (&$days, $timezone): void {
                $key = $order->completed_at?->setTimezone($timezone)->toDateString();

                if ($key !== null && isset($days[$key])) {
                    $days[$key]['revenue'] = Money::add($days[$key]['revenue'], $order->grand_total);
                    $days[$key]['order_count']++;
                }
            });

        return array_values($days);
    }

    /** @return Builder<Order> */
    private function completed(CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::Completed)
            ->whereBetween('completed_at', [$start, $end]);
    }
}
