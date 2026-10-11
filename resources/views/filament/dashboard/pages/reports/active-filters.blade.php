{{-- Baris bawah toolbar filter: tombol "Filter lanjutan" (panel samping) + badge filter aktif --}}
@php($pills = $this->activeFilterPills())

<div class="pos-report-filters__footer">
    {{ $this->advancedFiltersAction }}

    @foreach ($pills as $pill)
        <x-filament::badge color="primary" size="sm">{{ $pill }}</x-filament::badge>
    @endforeach
</div>
