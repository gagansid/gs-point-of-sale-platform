{{-- Sistem → Queue: kartu ringkas antrean, job di antrean (QueuedJobsWidget), job gagal (tabel halaman). --}}
@php
    $stats = $this->stats();
    $cron = $this->scheduler();
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<x-filament-panels::page>
    <div class="gs-overview">
        <div class="gs-kpi-grid">
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-clock" /></span><span class="gs-kpi-label">Menunggu</span></div>
                <div class="gs-kpi-value tabular-nums">{{ $num($stats['pending']) }}</div>
                <div class="gs-kpi-hint">Tertua {{ $stats['oldest']?->locale('id')->diffForHumans() ?? '—' }}</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-path" /></span><span class="gs-kpi-label">Diproses / terjadwal</span></div>
                <div class="gs-kpi-value tabular-nums">{{ $num($stats['processing']) }} / {{ $num($stats['delayed']) }}</div>
                <div class="gs-kpi-hint">Terjadwal = menunggu waktu jalan (retry, tunda)</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-exclamation-triangle" /></span><span class="gs-kpi-label">Job gagal</span></div>
                <div class="gs-kpi-value tabular-nums">{{ $num($stats['failed']) }}</div>
                <div class="gs-kpi-hint">{{ $num($stats['failed_24h']) }} dalam 24 jam terakhir</div>
            </div>
            <div class="gs-kpi">
                <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-cpu-chip" /></span><span class="gs-kpi-label">Cron scheduler</span></div>
                <div class="gs-kpi-value">
                    @if ($cron['healthy'])
                        <x-filament::badge color="success" icon="heroicon-o-check-circle">Berjalan</x-filament::badge>
                    @else
                        <x-filament::badge color="danger" icon="heroicon-o-x-circle">Tidak berjalan</x-filament::badge>
                    @endif
                </div>
                <div class="gs-kpi-hint">Detak terakhir {{ $cron['last']?->locale('id')->diffForHumans() ?? 'belum pernah' }}</div>
            </div>
        </div>

        @livewire(\App\Filament\Admin\Widgets\QueuedJobsWidget::class, ['showQueueLink' => false])

        {{ $this->table }}
    </div>
</x-filament-panels::page>
