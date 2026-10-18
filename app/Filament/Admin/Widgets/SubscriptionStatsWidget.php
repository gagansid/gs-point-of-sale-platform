<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Filament\Admin\Resources\SubscriptionInvoices\SubscriptionInvoiceResource;
use App\Filament\Dashboard\Widgets\Concerns\DescribesTrend;
use App\Services\Admin\DashboardCache;
use App\Services\Billing\SubscriptionMetrics;
use App\Support\Edition;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dasbor /admin: pendapatan langganan SaaS (ADR 0024) — lunas bulan ini vs bulan lalu, tagihan menunggu, estimasi MRR,
 * bisnis berlangganan per paket. Edisi jual putus tidak memakai tagihan, jadi widget disembunyikan.
 */
final class SubscriptionStatsWidget extends StatsOverviewWidget
{
    use DescribesTrend;

    // Dirender langsung & tanpa polling (shared hosting, stat-widget.md)
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Langganan';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Edition::isSaas();
    }

    /** @return array<string, int> */
    protected function getColumns(): array
    {
        return ['default' => 1, 'md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $metrics = app(SubscriptionMetrics::class);
        // Query lintas tenant di-cache 10 menit (Muat ulang data di Dasbor)
        $paid = DashboardCache::remember('paid-6', fn (): array => $metrics->paidPerMonth(6));
        $months = array_keys($paid);
        $thisMonth = $paid[$months[5]];
        $lastMonth = $paid[$months[4]];
        $lastLabel = CarbonImmutable::createFromFormat('Y-m', $months[4])->locale('id')->translatedFormat('F Y');
        $pending = DashboardCache::remember('pending', fn (): array => $metrics->pending());
        $active = DashboardCache::remember('active-subscriptions', fn (): array => $metrics->activeSubscriptions());
        $trials = DashboardCache::remember('trials', fn (): int => $metrics->trialCount());
        $perPlan = implode(' · ', array_map(fn (array $p): string => $p['name'].' '.$p['count'], $active['plans']));

        return [
            $this->trend(Stat::make('Lunas bulan ini', Money::format($thisMonth)), $thisMonth, $lastMonth, "bulan lalu ({$lastLabel}: ".Money::format($lastMonth).')')
                ->icon(Heroicon::OutlinedBanknotes)
                ->chart(array_map(fn (string $v): float => (float) $v, array_values($paid))),
            Stat::make('Tagihan menunggu', number_format($pending['count'], 0, ',', '.'))
                ->icon(Heroicon::OutlinedClock)
                ->description($pending['count'] > 0 ? Money::format($pending['amount']) : 'Tidak ada')
                ->color($pending['count'] > 0 ? 'warning' : 'gray')
                ->url(SubscriptionInvoiceResource::getUrl('index', ['filters' => ['status' => ['value' => 'pending']]])),
            Stat::make('Estimasi MRR', Money::format($active['mrr']))
                ->icon(Heroicon::OutlinedArrowPath)
                ->description('Tahunan dihitung ÷ 12, harga paket saat ini')
                ->color('gray'),
            Stat::make('Bisnis berlangganan', number_format($active['count'], 0, ',', '.'))
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->description(($perPlan !== '' ? $perPlan.' · ' : '').'trial '.$trials)
                ->color('gray')
                ->url(PlanResource::getUrl('index')),
        ];
    }
}
