<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\AdminChart;
use App\Services\Admin\PlatformMetrics;
use App\Support\Edition;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Dasbor /admin: bisnis baru terdaftar per bulan, 12 bulan terakhir. */
final class SignupsChartWidget extends ChartWidget
{
    use AdminChart;

    protected static bool $isLazy = false;

    protected static ?int $sort = 3;

    protected ?string $maxHeight = '280px';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Bisnis baru per bulan';

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    public function getDescription(): string
    {
        $total = array_sum(app(PlatformMetrics::class)->signupsPerMonth(12));

        return $total === 0 ? 'Belum ada pendaftaran dalam 12 bulan' : number_format($total, 0, ',', '.').' bisnis dalam 12 bulan';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $signups = app(PlatformMetrics::class)->signupsPerMonth(12);

        return [
            'datasets' => [self::barDataset('Bisnis baru', array_values($signups))],
            'labels' => self::monthLabels(array_keys($signups)),
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::countOptions('bisnis');
    }
}
