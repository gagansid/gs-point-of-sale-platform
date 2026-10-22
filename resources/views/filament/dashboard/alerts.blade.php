{{-- Lonceng peringatan di topbar (App\Support\DashboardAlerts): hitungan langsung dari data, tautan ke menu terkait --}}
@php
    /** @var list<array{key: string, icon: string, color: string, title: string, description: string, url: string}> $alerts */
    $count = count($alerts);
@endphp

<x-filament::dropdown placement="bottom-end" teleport width="sm" class="gs-alerts">
    <x-slot name="trigger">
        <button
            type="button"
            @class(['gs-theme-toggle gs-alerts-trigger', 'has-alerts' => $count > 0])
            aria-label="{{ $count > 0 ? $count.' peringatan' : 'Tidak ada peringatan' }}"
            x-tooltip="{ content: @js($count > 0 ? $count.' perlu perhatian' : 'Semua aman'), theme: $store.theme }"
        >
            <x-filament::icon icon="heroicon-o-bell" />
            @if ($count > 0)
                <span class="gs-alerts-badge">{{ $count > 9 ? '9+' : $count }}</span>
            @endif
        </button>
    </x-slot>

    <div class="gs-alerts-head">
        <span>Perlu perhatian</span>
        @if ($count > 0)
            <span class="gs-alerts-count">{{ $count }}</span>
        @endif
    </div>

    @if ($count === 0)
        <div class="gs-alerts-empty">
            <x-filament::icon icon="heroicon-o-check-circle" />
            <p>Semua aman. Tidak ada yang perlu ditindak sekarang.</p>
        </div>
    @else
        <ul class="gs-alerts-list">
            @foreach ($alerts as $alert)
                <li wire:key="alert-{{ $alert['key'] }}">
                    <a href="{{ $alert['url'] }}" class="gs-alerts-item">
                        <span class="gs-alerts-icon is-{{ $alert['color'] }}">
                            <x-filament::icon :icon="$alert['icon']" />
                        </span>
                        <span class="gs-alerts-text">
                            <span class="gs-alerts-title">{{ $alert['title'] }}</span>
                            <span class="gs-alerts-desc">{{ $alert['description'] }}</span>
                        </span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="gs-alerts-chevron" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::dropdown>
