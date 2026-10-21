{{-- Pemilih warna utama (Q128): kartu swatch per preset + "Warna bebas"; nilai = kunci preset / "custom". --}}
@php
    /** @var array<string, array{0: string, 1: string}> $presets */
    $statePath = $getStatePath();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="gs-swatches" role="radiogroup" aria-label="Warna utama">
        @foreach ($presets as $key => [$label, $hex])
            <label class="gs-swatch" wire:key="swatch-{{ $key }}">
                <input type="radio" value="{{ $key }}" wire:model.live="{{ $statePath }}" class="sr-only">
                <span class="gs-swatch-dot" style="background: {{ $hex }}"></span>
                <span class="gs-swatch-label">{{ $label }}</span>
            </label>
        @endforeach
        <label class="gs-swatch">
            <input type="radio" value="{{ \App\Support\TenantAppearance::CUSTOM }}" wire:model.live="{{ $statePath }}" class="sr-only">
            <span class="gs-swatch-dot is-custom"></span>
            <span class="gs-swatch-label">Warna bebas</span>
        </label>
    </div>
</x-dynamic-component>
