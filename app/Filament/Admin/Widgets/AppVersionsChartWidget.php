<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Widgets\Concerns\AdminChart;
use App\Services\Admin\PlatformActivity;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Dasbor /admin (D3): versi aplikasi kasir Android pada perangkat aktif 30 hari (sebelum menaikkan versi minimum). */
final class AppVersionsChartWidget extends ChartWidget
{
    use AdminChart;

    protected static bool $isLazy = false;

    protected static ?int $sort = 14;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '220px';

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Versi aplikasi Android (perangkat aktif 30 hari)';

    /** Tanpa perangkat Android aktif chart hanya sumbu kosong: tidak ditampilkan. */
    public static function canView(): bool
    {
        return app(PlatformActivity::class)->androidVersions()['versions'] !== [];
    }

    public function getDescription(): string
    {
        $versions = app(PlatformActivity::class)->androidVersions();
        if ($versions['versions'] === []) {
            return 'Belum ada perangkat Android aktif';
        }

        return 'Versi minimum: '.($versions['min'] ?? 'belum diatur')
            .($versions['below_min'] > 0 ? ' · '.$versions['below_min'].' perangkat wajib update (ditolak API)' : ' · semua perangkat memenuhi');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $versions = app(PlatformActivity::class)->androidVersions()['versions'];

        return [
            'datasets' => [self::barDataset('Perangkat', array_values($versions))],
            'labels' => array_map(fn (string $v): string => $v === '?' ? 'Tidak diketahui' : 'v'.$v, array_keys($versions)),
        ];
    }

    protected function getOptions(): RawJs
    {
        return self::horizontalCountOptions('perangkat');
    }
}
