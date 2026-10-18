<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\AdminChart;
use App\Services\Admin\PlatformMetrics;
use App\Support\Edition;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Dasbor /admin: bisnis per jenis usaha (bar horizontal satu warna, terbanyak di atas). */
final class TenantsPerBusinessTypeChartWidget extends ChartWidget
{
    use AdminChart;

    protected static bool $isLazy = false;

    protected static ?int $sort = 5;

    protected ?string $maxHeight = '240px';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Bisnis per jenis usaha';

    protected ?string $description = 'Semua bisnis terdaftar';

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = app(PlatformMetrics::class)->tenantsPerBusinessType();

        return [
            'datasets' => [self::barDataset('Bisnis', array_values($rows))],
            'labels' => array_keys($rows),
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::horizontalCountOptions('bisnis');
    }
}
