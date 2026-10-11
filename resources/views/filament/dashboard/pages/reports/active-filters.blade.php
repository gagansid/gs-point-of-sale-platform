{{-- Ringkasan filter lanjutan yang aktif (badge kecil di bawah form) --}}
@php($pills = $this->activeFilterPills())

@if ($pills !== [])
    <div class="flex flex-wrap items-center gap-2" aria-label="Filter aktif">
        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Filter aktif:</span>
        @foreach ($pills as $pill)
            <x-filament::badge color="primary" size="sm">{{ $pill }}</x-filament::badge>
        @endforeach
    </div>
@endif
