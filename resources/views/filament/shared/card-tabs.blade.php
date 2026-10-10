{{--
    Tab cepat di header card tabel (App\Filament\Shared\Concerns\HasCardTabs).
    Klik → $set('activeTab') → Livewire memuat ulang data dari server dan menyimpan tab di session.
--}}
@props(['tabs', 'activeTab'])

@if (count($tabs))
    <nav class="gs-card-tabs" role="tablist" aria-label="Tab daftar">
        @foreach ($tabs as $key => $tab)
            @php
                $isActive = (string) $key === (string) $activeTab;
                $badge = $tab->getBadge();
            @endphp

            <button
                type="button"
                role="tab"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                wire:click="$set('activeTab', @js((string) $key))"
                wire:loading.attr="disabled"
                @class(['gs-card-tab', 'gs-active' => $isActive])
            >
                @if ($icon = $tab->getIcon())
                    {{ \Filament\Support\generate_icon_html($icon, attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'gs-card-tab-icon'])) }}
                @endif

                <span>{{ $tab->getLabel() }}</span>

                @if (filled($badge))
                    <span @class([
                        'gs-card-tab-badge',
                        'gs-color-' . $tab->getBadgeColor() => filled($tab->getBadgeColor()) && $badge,
                    ])>{{ $badge }}</span>
                @endif
            </button>
        @endforeach
    </nav>
@endif
