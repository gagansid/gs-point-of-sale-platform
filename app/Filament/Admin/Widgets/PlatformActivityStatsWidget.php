<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Dashboard\Widgets\Concerns\DescribesTrend;
use App\Services\Admin\PlatformActivity;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dasbor /admin (D3): aktivitas kasir semua bisnis — transaksi, nilai transaksi, bisnis bertransaksi, perangkat. */
final class PlatformActivityStatsWidget extends StatsOverviewWidget
{
    use DescribesTrend;

    protected static bool $isLazy = false;

    protected static ?int $sort = 10;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Aktivitas platform (30 hari)';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $activity = app(PlatformActivity::class);
        $totals = $activity->totals(30);
        $perDay = $activity->perDay(30);
        $devices = $activity->devices();
        $versions = $activity->androidVersions();
        $number = fn (int $n): string => number_format($n, 0, ',', '.');
        $versus = '30 hari sebelumnya';

        return [
            $this->trend(Stat::make('Transaksi', $number($totals['count'])), (string) $totals['count'], (string) $totals['previous_count'], "{$versus} ({$number($totals['previous_count'])})")
                ->icon(Heroicon::OutlinedReceiptPercent)
                ->chart(array_map(fn (array $d): float => (float) $d['count'], array_values($perDay))),
            $this->trend(Stat::make('Nilai transaksi', Money::format($totals['gmv'])), $totals['gmv'], $totals['previous_gmv'], $versus.' ('.Money::format($totals['previous_gmv']).')')
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart(array_map(fn (array $d): float => (float) $d['gmv'], array_values($perDay))),
            Stat::make('Bisnis bertransaksi', $number($totals['tenants']))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->description('Minimal 1 transaksi selesai dalam 30 hari')
                ->color('gray'),
            Stat::make('Perangkat aktif (7 hari)', $number($devices['active']))
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->description($versions['below_min'] > 0
                    ? $number($versions['below_min']).' Android di bawah versi minimum '.$versions['min']
                    : 'Android '.$number($devices['android']).' · kasir web '.$number($devices['web']).' · dari '.$number($devices['total']).' terdaftar')
                ->color($versions['below_min'] > 0 ? 'warning' : 'gray'),
        ];
    }
}
