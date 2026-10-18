<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\AdminChart;
use App\Services\Admin\PlatformActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Dasbor /admin (D3): nilai transaksi semua bisnis per hari, 30 hari (area satu seri; jumlah transaksi di judul). */
final class TransactionsChartWidget extends ChartWidget
{
    use AdminChart;

    protected static bool $isLazy = false;

    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '260px';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Nilai transaksi harian semua bisnis';

    public function getDescription(): string
    {
        $totals = app(PlatformActivity::class)->totals(30);

        return $totals['count'] === 0
            ? 'Belum ada transaksi dalam 30 hari'
            : number_format($totals['count'], 0, ',', '.').' transaksi · '.Money::format($totals['gmv']).' dalam 30 hari';
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $perDay = app(PlatformActivity::class)->perDay(30);

        return [
            'datasets' => [[
                'label' => 'Nilai transaksi',
                // float hanya untuk menggambar grafik; angka resmi tetap string desimal
                'data' => array_map(fn (array $d): float => (float) $d['gmv'], array_values($perDay)),
                'borderColor' => self::PRIMARY,
                'borderWidth' => 2,
                'backgroundColor' => 'rgba(45, 66, 130, 0.08)',
                'fill' => true,
                'cubicInterpolationMode' => 'monotone',
                'pointRadius' => 0,
                'pointHoverRadius' => 4,
            ]],
            'labels' => array_map(fn (string $d): string => CarbonImmutable::parse($d)->locale('id')->translatedFormat('j M'), array_keys($perDay)),
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::rupiahOptions();
    }
}
