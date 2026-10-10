{{-- Pemilih outlet topbar (ADR 0010): hanya tampil bila user memegang lebih dari satu outlet --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Outlet> $outlets */
    $selected = $outlets->firstWhere('id', $selectedId);
    $label = $selected?->name ?? 'Semua outlet';
@endphp

<x-filament::dropdown placement="bottom-start" teleport class="pos-outlet-switcher">
    <x-slot name="trigger">
        <button type="button" class="pos-outlet-switcher__trigger" aria-label="Pilih outlet">
            <x-filament::icon icon="heroicon-o-building-storefront" class="pos-outlet-switcher__icon" />
            <span class="pos-outlet-switcher__label">{{ $label }}</span>
            <x-filament::icon icon="heroicon-m-chevron-down" class="pos-outlet-switcher__chevron" />
        </button>
    </x-slot>

    <x-filament::dropdown.list>
        <x-filament::dropdown.list.item
            tag="form"
            method="post"
            :action="route('filament.dashboard.switch-outlet', ['outlet' => 'all'])"
            :icon="$selectedId === null ? 'heroicon-m-check' : 'heroicon-o-squares-2x2'"
            :color="$selectedId === null ? 'primary' : 'gray'"
        >
            Semua outlet
        </x-filament::dropdown.list.item>

        @foreach ($outlets as $outlet)
            <x-filament::dropdown.list.item
                tag="form"
                method="post"
                :action="route('filament.dashboard.switch-outlet', ['outlet' => $outlet->id])"
                :icon="$selectedId === $outlet->id ? 'heroicon-m-check' : 'heroicon-o-building-storefront'"
                :color="$selectedId === $outlet->id ? 'primary' : 'gray'"
            >
                {{ $outlet->name }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
