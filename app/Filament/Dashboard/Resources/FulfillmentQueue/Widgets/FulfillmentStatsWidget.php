<?php

declare(strict_types=1);

namespace App\Filament\Dashboard\Resources\FulfillmentQueue\Widgets;

use App\Enums\FulfillmentStatus;
use App\Filament\Dashboard\Resources\FulfillmentQueue\FulfillmentQueueResource;
use App\Models\Order;
use App\Support\CurrentOutlet;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Antrean pesanan: statistik hari ini (zona outlet, outlet pilihan sidebar) — jumlah per tahap, rata-rata lunas →
 * diantar, dan pesanan terlambat (stat-widget.md). Polling 10 detik bersama tabel.
 */
final class FulfillmentStatsWidget extends StatsOverviewWidget
{
    // Dirender langsung: widget lazy = 1 request HTTP per widget (berat di shared hosting)
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '10s';

    /** @return array<string, int> 2 kolom (HP) → 4 (md) */
    protected function getColumns(): array
    {
        return ['default' => 2, 'md' => 4];
    }

    protected function getStats(): array
    {
        $range = CurrentOutlet::utcRange(CurrentOutlet::today(), CurrentOutlet::today());
        $today = fn () => FulfillmentQueueResource::getEloquentQuery()->whereBetween('completed_at', $range);
        $counts = $today()->selectRaw('fulfillment_status, count(*) as total')->groupBy('fulfillment_status')->pluck('total', 'fulfillment_status');
        $count = fn (FulfillmentStatus $status): int => (int) ($counts[$status->value] ?? 0);
        $format = fn (int $n): string => number_format($n, 0, ',', '.');

        // Rata-rata lunas → diantar (staf menandai Diantar), menit
        $served = $today()->where('fulfillment_status', FulfillmentStatus::Served)->get(['completed_at', 'served_at']);
        $average = $served->isEmpty() ? null : (int) round($served->avg(fn (Order $o): int => (int) $o->completed_at?->diffInMinutes($o->served_at)));
        $late = FulfillmentQueueResource::late(FulfillmentQueueResource::getEloquentQuery())->count();

        return [
            Stat::make('Antre', $format($count(FulfillmentStatus::Queued)))->icon(Heroicon::OutlinedInboxArrowDown)
                ->description('Lunas, belum disiapkan')->color($count(FulfillmentStatus::Queued) > 0 ? 'warning' : 'gray'),
            Stat::make('Disiapkan', $format($count(FulfillmentStatus::Preparing)))->icon(Heroicon::OutlinedFire)
                ->description('Sedang dibuat'),
            Stat::make('Siap diantar', $format($count(FulfillmentStatus::Ready)))->icon(Heroicon::OutlinedBellAlert)
                ->description('Menunggu diantar ke meja'),
            Stat::make('Diantar', $format($count(FulfillmentStatus::Served)))->icon(Heroicon::OutlinedCheckCircle)
                ->description('Ditandai staf hari ini')->color('success'),
            Stat::make('Selesai otomatis', $format($count(FulfillmentStatus::AutoCompleted)))->icon(Heroicon::OutlinedClock)
                ->description('Tidak ditandai Diantar'),
            Stat::make('Rata-rata lunas → diantar', $average === null ? '—' : $average.' mnt')->icon(Heroicon::OutlinedArrowTrendingUp)
                ->description($served->isEmpty() ? 'Belum ada yang diantar hari ini' : 'Dari '.$format($served->count()).' pesanan'),
            Stat::make('Terlambat', $format($late))->icon(Heroicon::OutlinedExclamationTriangle)
                ->description('Belum diantar > '.FulfillmentQueueResource::LATE_MINUTES.' menit')
                ->color($late > 0 ? 'danger' : 'gray'),
        ];
    }
}
