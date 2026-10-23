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
 * Antrean pesanan: ringkasan hari ini (zona outlet, outlet pilihan sidebar) dalam empat kartu — dalam antrean (rincian
 * per tahap), diantar, waktu antar rata-rata, terlambat (stat-widget.md). Polling 10 detik bersama tabel.
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
        $servedOrders = $today()->where('fulfillment_status', FulfillmentStatus::Served)->get(['completed_at', 'served_at']);
        $average = $servedOrders->isEmpty() ? null : (int) round($servedOrders->avg(fn (Order $o): int => (int) $o->completed_at?->diffInMinutes($o->served_at)));
        $late = FulfillmentQueueResource::late(FulfillmentQueueResource::getEloquentQuery())->count();

        $open = $count(FulfillmentStatus::Queued) + $count(FulfillmentStatus::Preparing) + $count(FulfillmentStatus::Ready);
        $served = $servedOrders->count();
        $auto = $count(FulfillmentStatus::AutoCompleted);

        // Empat kartu satu baris; rincian per tahap ada di tab tabel di bawahnya
        return [
            Stat::make('Dalam antrean', $format($open))->icon(Heroicon::OutlinedInboxStack)
                ->description($format($count(FulfillmentStatus::Queued)).' antre · '.$format($count(FulfillmentStatus::Preparing)).' dibuat · '.$format($count(FulfillmentStatus::Ready)).' siap')
                ->color($open > 0 ? 'warning' : 'gray'),
            Stat::make('Diantar hari ini', $format($served))->icon(Heroicon::OutlinedCheckCircle)
                ->description($auto > 0 ? '+'.$format($auto).' selesai otomatis' : 'Ditandai staf')
                ->color($served > 0 ? 'success' : 'gray'),
            Stat::make('Waktu antar rata-rata', $average === null ? '—' : $average.' mnt')->icon(Heroicon::OutlinedClock)
                ->description($average === null ? 'Belum ada data' : 'Lunas → diantar'),
            Stat::make('Terlambat', $format($late))->icon(Heroicon::OutlinedExclamationTriangle)
                ->description('Lewat '.FulfillmentQueueResource::LATE_MINUTES.' menit')
                ->color($late > 0 ? 'danger' : 'gray'),
        ];
    }
}
