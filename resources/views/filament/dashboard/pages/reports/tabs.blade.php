{{-- Tab jenis laporan (tabs.md): hanya tab yang boleh dilihat user; ?tab= di URL --}}
@php($active = $this->activeTab())

<x-filament::tabs label="Jenis laporan" class="pos-report-tabs">
    @foreach ($this->visibleTabs() as $tab)
        <x-filament::tabs.item
            :active="$active === $tab"
            :icon="$tab->icon()"
            wire:click="$set('tab', '{{ $tab->value }}')"
            wire:key="report-tab-{{ $tab->value }}"
        >
            {{ $tab->label() }}
        </x-filament::tabs.item>
    @endforeach
</x-filament::tabs>
