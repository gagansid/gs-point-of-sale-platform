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
 * Laporan › Laba kotor: penjualan bersih, HPP, laba kotor, margin (R1, Q53).
 */
final class ProfitStatsWidget extends StatsOverviewWidget
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
        $totals = app(ReportService::class)->profit($start, $end, $this->context()->filters)['totals'];
        $uncosted = $totals['uncosted_qty'] > 0
            ? number_format($totals['uncosted_qty'], 0, ',', '.').' item tanpa harga modal ('.Money::format($totals['uncosted_sales']).') tidak dihitung'
            : null;

        return [
            Stat::make('Penjualan bersih', Money::format($totals['net_sales']))
                ->description('Setelah diskon, tanpa pajak & service')->icon(Heroicon::OutlinedBanknotes),
            Stat::make('HPP', Money::format($totals['cogs']))
                ->description('Harga modal saat transaksi')->icon(Heroicon::OutlinedArchiveBox),
            Stat::make('Laba kotor', Money::format($totals['gross_profit']))
                ->description($uncosted)->descriptionColor($uncosted !== null ? 'warning' : null)
                ->color(Money::compare($totals['gross_profit'], '0') < 0 ? 'danger' : null)
                ->icon(Heroicon::OutlinedScale),
            Stat::make('Margin kotor', $totals['margin'] !== null ? str_replace('.', ',', $totals['margin']).'%' : '–')
                ->description('Laba kotor ÷ penjualan ber-harga modal')->icon(Heroicon::OutlinedReceiptPercent),
        ];
    }
}
