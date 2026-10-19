{{-- Sistem → Pendaftaran: ringkasan pendaftar mandiri + pendaftar terbaru (OnboardingSettings::overview). --}}
@php
    $num = fn (int $n): string => number_format($n, 0, ',', '.');
@endphp
<div class="gs-overview">
    <div class="gs-kpi-grid">
        <div class="gs-kpi">
            <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-globe-alt" /></span><span class="gs-kpi-label">Status pendaftaran</span></div>
            <div class="gs-kpi-value">
                @if ($open)
                    <x-filament::badge color="success" icon="heroicon-o-lock-open">Dibuka</x-filament::badge>
                @else
                    <x-filament::badge color="gray" icon="heroicon-o-lock-closed">Ditutup</x-filament::badge>
                @endif
            </div>
            <div class="gs-kpi-hint">{{ $limit > 0 ? 'Batas '.$num($limit).' bisnis/hari' : 'Tanpa batas harian' }}</div>
        </div>
        <div class="gs-kpi">
            <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-user-plus" /></span><span class="gs-kpi-label">Pendaftar hari ini</span></div>
            <div class="gs-kpi-value tabular-nums">{{ $num($today) }}{{ $limit > 0 ? ' / '.$num($limit) : '' }}</div>
            <div class="gs-kpi-hint">7 hari {{ $num($week) }} · 30 hari {{ $num($month) }}</div>
        </div>
        <div class="gs-kpi">
            <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-clock" /></span><span class="gs-kpi-label">Trial berjalan</span></div>
            <div class="gs-kpi-value tabular-nums">{{ $num($trials) }}</div>
            <div class="gs-kpi-hint">Semua bisnis berstatus trial</div>
        </div>
        <div class="gs-kpi">
            <div class="gs-kpi-head"><span class="gs-kpi-icon"><x-filament::icon icon="heroicon-o-arrow-trending-up" /></span><span class="gs-kpi-label">Konversi 90 hari</span></div>
            <div class="gs-kpi-value tabular-nums">{{ $conversion === null ? '—' : $conversion.'%' }}</div>
            <div class="gs-kpi-hint">{{ $num($converted) }} dari {{ $num($total90) }} pendaftar kini berlangganan</div>
        </div>
    </div>

    <x-filament::section icon="heroicon-o-user-plus" heading="Pendaftar terbaru" description="Daftar sendiri dari halaman depan (bukan dibuat admin/sales)">
        <x-slot name="afterHeader">
            <x-filament::link :href="$allUrl" size="sm">Semua tenant</x-filament::link>
        </x-slot>
        @if ($recent->isEmpty())
            <p class="gs-detail-empty">Belum ada pendaftar mandiri</p>
        @else
            <div class="overflow-x-auto">
                <table class="gs-detail-table">
                    <thead>
                        <tr><th>Bisnis</th><th>Status</th><th>Paket</th><th>Aktif sampai</th><th class="is-end">Daftar</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $tenant)
                            <tr wire:key="signup-{{ $tenant->id }}">
                                <td>
                                    <a href="{{ $tenantUrl($tenant) }}" class="gs-detail-name hover:underline">{{ $tenant->name }}</a>
                                    <div class="gs-detail-sub">{{ $tenant->business_type->getLabel() }}</div>
                                </td>
                                <td><x-filament::badge :color="$tenant->status->getColor()">{{ $tenant->status->getLabel() }}</x-filament::badge></td>
                                <td>{{ $tenant->plan?->name ?? 'Semua fitur' }}</td>
                                <td>{{ $tenant->subscription_ends_at?->setTimezone($timezone)->locale('id')->translatedFormat('j M Y') ?? '—' }}</td>
                                <td class="is-end" title="{{ $tenant->created_at->setTimezone($timezone)->translatedFormat('j M Y, H.i') }}">{{ $tenant->created_at->locale('id')->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
