<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\ReportWidget;
use App\Services\Report\ReportService;
use App\Support\CurrentOutlet;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Jam ramai: jumlah transaksi per jam lokal outlet sepanjang periode (untuk atur shift & stok).
 * Hanya jam operasional (jam pertama–terakhir yang ada transaksi) agar grafik tidak kosong.
 */
final class PeakHoursChartWidget extends ChartWidget
{
    use ReportWidget;

    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Jam ramai';

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    /** @var list<array{hour: int, revenue: string, order_count: int}>|null */
    private ?array $hours = null;

    public function getDescription(): string
    {
        $hours = $this->hours();
        $peak = collect($hours)->sortByDesc('order_count')->first();

        if ($peak === null || $peak['order_count'] === 0) {
            return 'Belum ada transaksi pada periode ini';
        }

        return sprintf('Tersibuk pukul %02d.00–%02d.00 (%s transaksi)', $peak['hour'], ($peak['hour'] + 1) % 24, number_format($peak['order_count'], 0, ',', '.'));
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $hours = $this->hours();
        $max = max(array_column($hours, 'order_count') ?: [0]);

        return [
            'datasets' => [[
                'label' => 'Transaksi',
                'data' => array_column($hours, 'order_count'),
                // Jam tersibuk primary penuh, lainnya primary transparan
                'backgroundColor' => array_map(fn (array $h): string => $max > 0 && $h['order_count'] === $max ? '#2D4282' : 'rgba(45, 66, 130, 0.35)', $hours),
                'borderRadius' => 4,
            ]],
            'labels' => array_map(fn (array $h): string => sprintf('%02d', $h['hour']), $hours),
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
            {
                animation: { duration: 0 },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: {
                        title: (items) => 'Pukul ' + items[0].label + '.00',
                        label: (ctx) => ctx.parsed.y.toLocaleString('id-ID') + ' transaksi',
                    } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                },
            }
        JS);
    }

    /** @return list<array{hour: int, revenue: string, order_count: int}> */
    private function hours(): array
    {
        if ($this->hours !== null) {
            return $this->hours;
        }

        [$start, $end] = $this->period()->utcRange();
        $hours = app(ReportService::class)->hourly($start, $end, CurrentOutlet::timezone(), $this->reportFilters());
        $active = array_keys(array_filter($hours, fn (array $h): bool => $h['order_count'] > 0));

        // Default jam buka kafe bila belum ada transaksi
        $first = $active !== [] ? min(min($active), 7) : 7;
        $last = $active !== [] ? max(max($active), 21) : 21;

        return $this->hours = array_slice($hours, $first, $last - $first + 1);
    }
}
