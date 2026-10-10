<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Komposisi metode bayar periode terpilih (chart.md: maks. 5 irisan, sisanya "Lainnya").
 */
final class PaymentMethodsChartWidget extends ChartWidget
{
    use ReportWidget;

    private const COLORS = ['#2D4282', '#066FD1', '#2FB344', '#F59F00', '#8A919E'];

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Komposisi metode bayar';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    /** @var list<array{name: string, amount: string, count: int}>|null Dihitung sekali per request */
    private ?array $slices = null;

    public function getDescription(): string
    {
        $slices = $this->slices();

        if ($slices === []) {
            return 'Belum ada pembayaran pada periode ini';
        }

        $total = Money::add('0', ...array_column($slices, 'amount'));
        $top = $slices[0];

        return $top['name'].' terbanyak: '.Money::format($top['amount']).' ('.Money::round(bcdiv(bcmul($top['amount'], '100', 4), $total, 4), 0).'%)';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $slices = $this->slices();

        return [
            'datasets' => [[
                'data' => array_map(fn (array $r): float => (float) $r['amount'], $slices),
                'backgroundColor' => array_slice(self::COLORS, 0, max(count($slices), 1)),
                'borderWidth' => 0,
            ]],
            'labels' => array_column($slices, 'name'),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                cutout: '62%',
                scales: { x: { display: false }, y: { display: false } },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: (ctx) => {
                        const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                        const pct = total > 0 ? Math.round(ctx.parsed / total * 100) : 0;
                        return ctx.label + ': Rp' + Math.round(ctx.parsed).toLocaleString('id-ID') + ' (' + pct + '%)';
                    } } },
                },
            }
        JS);
    }

    /**
     * Maks. 5 irisan: 4 terbesar + "Lainnya".
     *
     * @return list<array{name: string, amount: string, count: int}>
     */
    private function slices(): array
    {
        if ($this->slices !== null) {
            return $this->slices;
        }

        [$start, $end] = $this->period()->utcRange();
        $rows = app(ReportService::class)->paymentMethods($start, $end, $this->reportFilters());
        $slices = array_map(fn (array $r): array => ['name' => $r['name'], 'amount' => $r['amount'], 'count' => $r['count']], $rows);

        if (count($slices) > 5) {
            $rest = array_slice($slices, 4);
            $slices = [...array_slice($slices, 0, 4), [
                'name' => 'Lainnya',
                'amount' => Money::add('0', ...array_column($rest, 'amount')),
                'count' => array_sum(array_column($rest, 'count')),
            ]];
        }

        return $this->slices = $slices;
    }
}
