<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Omzet 7 hari terakhir (chart.md: seri tunggal primary, animasi mati).
 */
final class RevenueChartWidget extends ChartWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Omzet 7 hari terakhir';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(ReportService::class)->daily(...[...$this->lastDays(7), CurrentOutlet::timezone()]);

        return [
            'datasets' => [[
                'label' => 'Omzet',
                // float hanya untuk menggambar grafik; angka resmi tetap string desimal
                'data' => array_map(fn (array $r): float => (float) $r['revenue'], $rows),
                'backgroundColor' => '#2D4282',
                'borderRadius' => 4,
            ]],
            'labels' => array_map(fn (array $r): string => CarbonImmutable::parse($r['date'])->locale('id')->translatedFormat('D j/n'), $rows),
        ];
    }

    /** Sumbu "Rp700 rb / Rp1,2 jt", tooltip nilai lengkap Rupiah (chart.md). */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (ctx) => 'Rp' + Math.round(ctx.parsed.y).toLocaleString('id-ID') } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { ticks: { callback: (v) => v >= 1000000
                        ? 'Rp' + (v / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
                        : 'Rp' + (v / 1000).toLocaleString('id-ID') + ' rb' } },
                },
            }
        JS);
    }
}
