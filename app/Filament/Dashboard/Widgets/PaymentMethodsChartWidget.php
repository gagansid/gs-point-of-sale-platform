<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Komposisi metode bayar 7 hari terakhir (chart.md: maks. 5 kategori, warna kategori netral).
 */
final class PaymentMethodsChartWidget extends ChartWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Komposisi metode bayar (7 hari)';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = app(ReportService::class)->paymentMethods(...$this->lastDays(7));

        return [
            'datasets' => [[
                'data' => array_map(fn (array $r): float => (float) $r['amount'], $rows),
                'backgroundColor' => ['#2D4282', '#066FD1', '#2FB344', '#F59F00', '#8A919E'],
            ]],
            'labels' => array_column($rows, 'name'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: (ctx) => ctx.label + ': Rp' + Math.round(ctx.parsed).toLocaleString('id-ID') } },
                },
            }
        JS);
    }
}
