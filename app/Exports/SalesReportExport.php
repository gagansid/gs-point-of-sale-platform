<?php

declare(strict_types=1);

namespace App\Exports;

use App\Services\Report\ReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Laporan penjualan Excel: sheet Ringkasan, Harian, Per Produk, Per Metode Bayar.
 * Angka dari ReportService (sama persis dengan dashboard & API).
 */
final class SalesReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly string $from,
        private readonly string $to,
        private readonly string $timezone,
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
    ) {}

    /** @return list<ReportSheet> */
    public function sheets(): array
    {
        $summary = $this->reports->summary($this->start, $this->end);
        $period = "{$this->from} s/d {$this->to} ({$this->timezone})";

        return [
            new ReportSheet('Ringkasan', ['Keterangan', 'Nilai'], [
                ['Periode', $period],
                ['Jumlah transaksi', $summary['order_count']],
                ['Omzet', SafeCell::money($summary['revenue'])],
                ['Rata-rata transaksi', SafeCell::money($summary['average'])],
                ['Item terjual', $summary['items_sold']],
                ['Total diskon', SafeCell::money($summary['discount_total'])],
                ['Service charge', SafeCell::money($summary['service_total'])],
                ['Pajak', SafeCell::money($summary['tax_total'])],
                ['Pembulatan', SafeCell::money($summary['rounding_total'])],
                ['Transaksi void', $summary['void_count']],
                ['Nilai void', SafeCell::money($summary['void_total'])],
            ], moneyColumns: []),
            new ReportSheet('Harian', ['Tanggal', 'Transaksi', 'Omzet'], array_map(
                fn (array $d): array => [$d['date'], $d['order_count'], SafeCell::money($d['revenue'])],
                $this->reports->daily($this->start, $this->end, $this->timezone),
            ), moneyColumns: ['C']),
            new ReportSheet('Per Produk', ['Produk', 'Qty', 'Omzet'], array_map(
                fn (array $p): array => [SafeCell::text($p['product_name']), $p['qty'], SafeCell::money($p['revenue'])],
                $this->reports->products($this->start, $this->end),
            ), moneyColumns: ['C']),
            new ReportSheet('Per Metode Bayar', ['Metode', 'Transaksi', 'Nominal'], array_map(
                fn (array $m): array => [SafeCell::text($m['name']), $m['count'], SafeCell::money($m['amount'])],
                $this->reports->paymentMethods($this->start, $this->end),
            ), moneyColumns: ['C']),
        ];
    }
}
