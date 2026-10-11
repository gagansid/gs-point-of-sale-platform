<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\DescribesTrend;
use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Laporan › Ringkasan: 4 KPI dengan % perubahan vs pembanding (stat-widget.md).
 */
final class ReportStatsWidget extends StatsOverviewWidget
{
    use DescribesTrend;
    use ReportWidget;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $reports = app(ReportService::class);
        $context = $this->context();
        [$start, $end] = $context->range();

        $now = $reports->summary($start, $end, $context->filters);
        $before = ($range = $context->comparisonRange()) !== null ? $reports->summary($range[0], $range[1], $context->filters) : null;
        $versus = $context->versusLabel() !== null ? $context->versusLabel().' ('.$context->comparison?->label().')' : null;
        $count = fn (int $n): string => number_format($n, 0, ',', '.');

        return [
            $this->trend(Stat::make('Omzet', Money::format($now['revenue'])), $now['revenue'], $before['revenue'] ?? null, $versus)
                ->icon(Heroicon::OutlinedBanknotes),
            $this->trend(Stat::make('Transaksi', $count($now['order_count'])), (string) $now['order_count'], isset($before) ? (string) $before['order_count'] : null, $versus)
                ->icon(Heroicon::OutlinedReceiptPercent),
            $this->trend(Stat::make('Rata-rata transaksi', Money::format($now['average'])), $now['average'], $before['average'] ?? null, $versus)
                ->icon(Heroicon::OutlinedCalculator),
            $this->trend(Stat::make('Item terjual', $count($now['items_sold'])), (string) $now['items_sold'], isset($before) ? (string) $before['items_sold'] : null, $versus)
                ->icon(Heroicon::OutlinedCube),
        ];
    }
}
