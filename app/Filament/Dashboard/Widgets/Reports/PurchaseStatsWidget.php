<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Laporan › Pembelian: nilai beli, dokumen stok masuk, qty, dan supplier pada periode (ADR 0015, Q83).
 */
final class PurchaseStatsWidget extends StatsOverviewWidget
{
    use ReportWidget;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 2, 'md' => 4];
    }

    protected function getStats(): array
    {
        [$start, $end] = $this->context()->range();
        $purchases = app(ReportService::class)->purchases($start, $end, $this->context()->filters);
        $totals = $purchases['totals'];
        $suppliers = count(array_filter($purchases['suppliers'], fn (array $r): bool => $r['supplier_id'] !== null));
        $average = $totals['receipt_count'] > 0 ? Money::div($totals['total_cost'], $totals['receipt_count']) : '0.00';

        return [
            Stat::make('Nilai beli', Money::formatWhole($totals['total_cost']))
                ->description('Stok masuk yang diposting')->icon(Heroicon::OutlinedBanknotes),
            Stat::make('Dokumen stok masuk', number_format($totals['receipt_count'], 0, ',', '.'))
                ->description('Rata-rata '.Money::formatWhole($average).' per dokumen')->icon(Heroicon::OutlinedArrowDownTray),
            Stat::make('Qty dibeli', number_format($totals['qty'], 0, ',', '.'))
                ->description('Semua produk & bahan')->icon(Heroicon::OutlinedArchiveBox),
            Stat::make('Supplier', number_format($suppliers, 0, ',', '.'))
                ->description('Yang mengirim barang pada periode ini')->icon(Heroicon::OutlinedTruck),
        ];
    }
}
