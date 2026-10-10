{{--
    Override panel filter Filament (docs/standards/ui/components/table.md):
    - Layout collapsible (di antara header card dan isi tabel): dibuka/tutup dengan animasi
      slide down / slide up lewat Alpine x-collapse (plugin yang sama dengan grup sidebar).
    - Tombol "Atur ulang" & "Terapkan filter" selalu di footer panel.
--}}
@props([
    'applyAction',
    'form',
    'headingTag' => 'h3',
    'resetAction' => null,
    'resetActionPosition' => null,
])

@php
    use Filament\Tables\Enums\FiltersResetActionPosition;

    $resetActionPosition ??= FiltersResetActionPosition::Header;
    // Hanya panel collapsible yang membawa x-show dari view tabel
    $isCollapsible = $attributes->has('x-show');
@endphp

<div
    @if ($isCollapsible)
        x-collapse.duration.250ms
    @endif
    {{ $attributes->class(['fi-ta-filters', 'gs-ta-filters-collapsible' => $isCollapsible]) }}
>
    <div class="fi-ta-filters-inner">
        <div class="fi-ta-filters-header">
            <{{ $headingTag }} class="fi-ta-filters-heading">
                {{ __('filament-tables::table.filters.heading') }}
            </{{ $headingTag }}>

            @if (($resetActionPosition === FiltersResetActionPosition::Header) && $resetAction?->isVisible())
                <div>
                    {{ $resetAction->defaultView($resetAction::LINK_VIEW) }}
                </div>
            @endif
        </div>

        {{ $form }}

        @if ($applyAction->isVisible() || (($resetActionPosition === FiltersResetActionPosition::Footer) && $resetAction?->isVisible()))
            <div class="fi-ta-filters-actions-ctn">
                @if ($applyAction->isVisible())
                    {{ $applyAction }}
                @endif

                @if (($resetActionPosition === FiltersResetActionPosition::Footer) && $resetAction?->isVisible())
                    {{ $resetAction }}
                @endif
            </div>
        @endif
    </div>
</div>
