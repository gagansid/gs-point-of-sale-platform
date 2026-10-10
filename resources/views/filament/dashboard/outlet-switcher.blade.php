{{-- Pemilih outlet di atas menu sidebar (ADR 0010): hanya tampil bila user memegang lebih dari satu outlet --}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Outlet> $outlets */
    $selected = $outlets->firstWhere('id', $selectedId);
    $label = $selected?->name ?? 'Semua outlet';
@endphp

<div class="pos-outlet-switcher">
    <x-filament::dropdown placement="bottom-start" teleport width="xs">
        <x-slot name="trigger">
            <button type="button" class="pos-outlet-switcher__trigger" aria-label="Pilih outlet" title="{{ $label }}">
                <span class="pos-outlet-switcher__badge">
                    <x-filament::icon :icon="$selected ? 'heroicon-o-building-storefront' : 'heroicon-o-squares-2x2'" />
                </span>
                <span class="pos-outlet-switcher__text">
                    <span class="pos-outlet-switcher__caption">Outlet</span>
                    <span class="pos-outlet-switcher__label">{{ $label }}</span>
                </span>
                <x-filament::icon icon="heroicon-o-chevron-up-down" class="pos-outlet-switcher__chevron" />
            </button>
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                tag="form"
                method="post"
                :action="route('filament.dashboard.switch-outlet', ['outlet' => 'all'])"
                :icon="$selectedId === null ? 'heroicon-o-check' : 'heroicon-o-squares-2x2'"
                :color="$selectedId === null ? 'primary' : 'gray'"
            >
                Semua outlet
            </x-filament::dropdown.list.item>

            @foreach ($outlets as $outlet)
                <x-filament::dropdown.list.item
                    tag="form"
                    method="post"
                    :action="route('filament.dashboard.switch-outlet', ['outlet' => $outlet->id])"
                    :icon="$selectedId === $outlet->id ? 'heroicon-o-check' : 'heroicon-o-building-storefront'"
                    :color="$selectedId === $outlet->id ? 'primary' : 'gray'"
                >
                    {{ $outlet->name }}
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
