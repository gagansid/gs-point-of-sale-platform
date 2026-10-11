<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Laporan › Kategori: komposisi omzet per kategori (chart.md: bar horizontal, maks. 5 + "Lainnya").
 */
final class CategoriesChartWidget extends ChartWidget
{
    use ReportWidget;

    private const COLORS = ['#2D4282', '#066FD1', '#2FB344', '#F59F00', '#8A919E'];

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Omzet per kategori';

    protected ?string $maxHeight = '360px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        [$start, $end] = $this->context()->range();
        $rows = app(ReportService::class)->categories($start, $end, $this->context()->filters);

        if (count($rows) > 5) {
            $rest = array_slice($rows, 4);
            $rows = [...array_slice($rows, 0, 4), ['category_name' => 'Lainnya', 'revenue' => Money::add('0', ...array_column($rest, 'revenue'))]];
        }

        return [
            'datasets' => [[
                'label' => 'Omzet',
                'data' => array_map(fn (array $r): float => (float) $r['revenue'], $rows),
                'backgroundColor' => array_slice(self::COLORS, 0, max(count($rows), 1)),
                'borderRadius' => 4,
            ]],
            'labels' => array_column($rows, 'category_name'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => 'Rp' + Math.round(ctx.parsed.x).toLocaleString('id-ID') } },
                },
                scales: {
                    y: { grid: { display: false } },
                    x: { beginAtZero: true, ticks: { callback: (v) => v >= 1000000
                        ? 'Rp' + (v / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
                        : 'Rp' + (v / 1000).toLocaleString('id-ID') + ' rb' } },
                },
            }
        JS);
    }
}
