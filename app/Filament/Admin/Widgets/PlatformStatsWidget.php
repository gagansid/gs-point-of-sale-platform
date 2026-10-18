<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Enums\TenantStatus;
use App\Filament\Admin\Resources\SalesLeads\SalesLeadResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Dashboard\Widgets\Concerns\DescribesTrend;
use App\Services\Admin\PlatformMetrics;
use App\Support\Edition;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Dasbor /admin: pertumbuhan bisnis pelanggan (SaaS) — berbayar, trial, bisnis baru, konversi trial. */
final class PlatformStatsWidget extends StatsOverviewWidget
{
    use DescribesTrend;

    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Bisnis pelanggan';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 3];
    }

    protected function getStats(): array
    {
        $metrics = app(PlatformMetrics::class);
        $counts = $metrics->tenantCounts();
        $new = $metrics->newTenants(30);
        $conversion = $metrics->trialConversion(90);
        $leads = $metrics->newLeads();
        $number = fn (int $n): string => number_format($n, 0, ',', '.');
        $percent = $conversion['cohort'] > 0 ? round($conversion['converted'] * 100 / $conversion['cohort'], 1) : null;

        return [
            Stat::make('Bisnis berbayar aktif', $number($counts['paid']))
                ->icon(Heroicon::OutlinedBuildingStorefront)
                ->description('dari '.$number($counts['total']).' bisnis terdaftar')
                ->color('gray')
                ->url(TenantResource::getUrl('index', ['tab' => 'active'])),
            Stat::make('Trial berjalan', $number($counts['trial']))
                ->icon(Heroicon::OutlinedClock)
                ->description($counts['trial_ending'] > 0 ? $number($counts['trial_ending']).' berakhir ≤ 7 hari' : 'Tidak ada yang segera berakhir')
                ->color($counts['trial_ending'] > 0 ? 'warning' : 'gray')
                ->url(TenantResource::getUrl('index', ['tab' => TenantStatus::Trial->value])),
            $this->trend(Stat::make('Bisnis baru (30 hari)', $number($new['current'])), (string) $new['current'], (string) $new['previous'], '30 hari sebelumnya ('.$number($new['previous']).')')
                ->icon(Heroicon::OutlinedUserPlus),
            Stat::make('Konversi trial (90 hari)', $percent === null ? '—' : str_replace('.', ',', (string) $percent).'%')
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->description($conversion['cohort'] > 0 ? $number($conversion['converted']).' dari '.$number($conversion['cohort']).' bisnis baru sudah membayar' : 'Belum ada bisnis baru')
                ->color('gray'),
            Stat::make('Hanya-baca / ditangguhkan', $number($counts['read_only']).' / '.$number($counts['suspended']))
                ->icon(Heroicon::OutlinedLockClosed)
                ->description('Masa aktif habis · diblokir admin')
                ->color($counts['read_only'] > 0 ? 'warning' : 'gray'),
            Stat::make('Calon pelanggan baru', $number($leads))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->description($leads > 0 ? 'Belum dihubungi' : 'Semua sudah ditindaklanjuti')
                ->color($leads > 0 ? 'warning' : 'gray')
                ->url(SalesLeadResource::getUrl('index')),
        ];
    }
}
