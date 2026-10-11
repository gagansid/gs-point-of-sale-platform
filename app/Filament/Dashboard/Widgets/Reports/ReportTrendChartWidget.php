<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets\Reports;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportPeriod;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Laporan › Ringkasan: tren omzet sesuai granularitas (jam/hari/minggu/bulan) + seri pembanding
 * abu-abu putus-putus (chart.md: tinggi 360px di Laporan, sumbu Rp singkat, animasi mati).
 */
final class ReportTrendChartWidget extends ChartWidget
{
    use ReportWidget;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '360px';

    protected ?string $pollingInterval = null;

    /** @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}|null */
    private ?array $series = null;

    /** @return list<string> */
    protected static function reportPermissions(): array
    {
        return ['report.view', 'report.view_shift'];
    }

    public function getHeading(): string
    {
        return 'Tren omzet '.mb_strtolower(ReportPeriod::GRANULARITIES[$this->context()->granularity]);
    }

    public function getDescription(): string
    {
        [$current] = $this->series();
        $total = Money::add('0', ...array_column($current, 'revenue'));

        return Money::compare($total, '0') === 0 ? 'Belum ada data pada periode ini' : 'Total '.Money::format($total);
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$current, $previous] = $this->series();

        $datasets = [[
            'label' => 'Omzet',
            // float hanya untuk menggambar grafik; angka resmi tetap string desimal
            'data' => array_map(fn (array $r): float => (float) $r['revenue'], $current),
            'borderColor' => '#2D4282',
            'backgroundColor' => 'rgba(45, 66, 130, 0.08)',
            'fill' => true,
            'cubicInterpolationMode' => 'monotone',
            'pointRadius' => count($current) > 31 ? 0 : 3,
        ]];

        if ($previous !== null) {
            $datasets[] = [
                'label' => 'Pembanding ('.$this->comparisonPeriod()?->label().')',
                'data' => array_map(fn (array $r): float => (float) $r['revenue'], $previous),
                'borderColor' => '#8A919E',
                'borderDash' => [6, 4],
                'backgroundColor' => 'transparent',
                'fill' => false,
                'cubicInterpolationMode' => 'monotone',
                'pointRadius' => 0,
            ];
        }

        return ['datasets' => $datasets, 'labels' => array_column($current, 'label')];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': Rp' + Math.round(ctx.parsed.y).toLocaleString('id-ID') } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { callback: (v) => v >= 1000000
                        ? 'Rp' + (v / 1000000).toLocaleString('id-ID', { maximumFractionDigits: 1 }) + ' jt'
                        : 'Rp' + (v / 1000).toLocaleString('id-ID') + ' rb' } },
                },
            }
        JS);
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null} */
    private function series(): array
    {
        if ($this->series !== null) {
            return $this->series;
        }

        $reports = app(ReportService::class);
        $context = $this->context();
        $timezone = CurrentOutlet::timezone();
        [$start, $end] = $context->range();

        $current = $reports->trend($start, $end, $timezone, $context->granularity, $context->filters);
        $previous = null;

        if (($range = $context->comparisonRange()) !== null) {
            // Disejajarkan per indeks bucket (hari ke-n, minggu ke-n, …)
            $previous = array_slice($reports->trend($range[0], $range[1], $timezone, $context->granularity, $context->filters), 0, count($current));
            $previous = array_pad($previous, count($current), ['revenue' => '0.00']);
        }

        return $this->series = [$current, $previous];
    }
}
