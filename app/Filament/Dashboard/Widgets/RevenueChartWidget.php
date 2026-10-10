<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Tren omzet periode terpilih (per jam bila 1 hari, per hari bila rentang) + garis putus-putus
 * periode pembanding (chart.md: seri utama primary, pembanding abu-abu, animasi mati).
 */
final class RevenueChartWidget extends ChartWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    /** @var array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}|null Dihitung sekali per request */
    private ?array $series = null;

    public function getHeading(): string
    {
        return $this->period()->isSingleDay() ? 'Tren omzet per jam' : 'Tren omzet harian';
    }

    public function getDescription(): string
    {
        [$current] = $this->series();
        $total = Money::add('0', ...array_column($current, 'revenue'));

        return Money::compare($total, '0') === 0
            ? 'Belum ada transaksi pada periode ini'
            : 'Total '.Money::format($total).' · '.$this->period()->label();
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        [$current, $previous] = $this->series();
        $single = $this->period()->isSingleDay();

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

        return [
            'datasets' => $datasets,
            'labels' => array_map(
                fn (array $r): string => $single
                    ? sprintf('%02d.00', $r['hour'])
                    : CarbonImmutable::parse($r['date'])->locale('id')->translatedFormat('D j/n'),
                $current,
            ),
        ];
    }

    /** Sumbu "Rp700 rb / Rp1,2 jt", tooltip nilai lengkap Rupiah (chart.md). */
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

    /**
     * Seri periode terpilih & pembanding (pembanding dipotong/diisi agar sejajar indeks).
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null}
     */
    private function series(): array
    {
        return $this->series ??= $this->loadSeries();
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>|null} */
    private function loadSeries(): array
    {
        $reports = app(ReportService::class);
        $timezone = CurrentOutlet::timezone();
        $filters = $this->reportFilters();
        $period = $this->period();
        $single = $period->isSingleDay();

        $fetch = fn (array $range): array => $single
            ? $reports->hourly($range[0], $range[1], $timezone, $filters)
            : $reports->daily($range[0], $range[1], $timezone, $filters);

        $current = $fetch($period->utcRange());
        $comparison = $this->comparisonPeriod();

        if ($comparison === null) {
            return [$current, null];
        }

        $previous = array_slice($fetch($comparison->utcRange()), 0, count($current));

        return [$current, array_pad($previous, count($current), ['revenue' => '0.00'])];
    }
}
