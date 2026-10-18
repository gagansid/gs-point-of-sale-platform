<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\AdminChart;
use App\Services\Admin\DashboardCache;
use App\Services\Billing\SubscriptionMetrics;
use App\Support\Edition;
use App\Support\Money;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Dasbor /admin: pendapatan langganan (tagihan lunas) per bulan, 12 bulan terakhir. */
final class SubscriptionRevenueChartWidget extends ChartWidget
{
    use AdminChart;

    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Pendapatan langganan per bulan';

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    public function getDescription(): string
    {
        $paid = $this->paid();
        $total = Money::add(...array_values($paid));

        return Money::compare($total, '0') === 0 ? 'Belum ada tagihan lunas dalam 12 bulan' : 'Total 12 bulan '.Money::format($total);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $paid = $this->paid();

        return [
            // float hanya untuk menggambar grafik; angka resmi tetap string desimal
            'datasets' => [self::barDataset('Lunas', array_map(fn (string $v): float => (float) $v, array_values($paid)))],
            'labels' => self::monthLabels(array_keys($paid)),
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::rupiahOptions();
    }

    /** @return array<string, string> */
    private function paid(): array
    {
        return DashboardCache::remember('paid-12', fn (): array => app(SubscriptionMetrics::class)->paidPerMonth(12));
    }
}
