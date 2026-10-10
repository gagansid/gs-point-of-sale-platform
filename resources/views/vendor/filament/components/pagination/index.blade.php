{{--
    Override paginasi Filament (docs/standards/ui/components/table.md):
    kiri  = ringkasan "1–7 dari 7" + pilihan jumlah per halaman,
    kanan = pager ‹ 1 2 3 › yang selalu tampil (tombol non-aktif jika tidak ada halaman lain).
    Setiap klik memanggil aksi Livewire (gotoPage/nextPage/previousPage) sehingga data
    selalu diambil ulang dari server per halaman — tidak ada paginasi di sisi browser.
--}}
@props([
    'currentPageOptionProperty' => 'tableRecordsPerPage',
    'extremeLinks' => false,
    'paginator',
    'pageOptions' => [],
])

@php
    use Filament\Support\Icons\Heroicon;
    use Filament\Support\View\SupportIconAlias;
    use Illuminate\Contracts\Pagination\CursorPaginator;
    use Illuminate\Pagination\LengthAwarePaginator;
    use Illuminate\Support\Number;

    $isRtl = __('filament-panels::layout.direction') === 'rtl';
    $isSimple = ! $paginator instanceof LengthAwarePaginator;
    $pageName = $paginator instanceof CursorPaginator ? $paginator->getCursorName() : $paginator->getPageName();

    if ($paginator instanceof CursorPaginator) {
        $previousAction = $paginator->onFirstPage() ? null : "setPage('{$paginator->previousCursor()->encode()}', '{$pageName}')";
        $nextAction = $paginator->hasMorePages() ? "setPage('{$paginator->nextCursor()->encode()}', '{$pageName}')" : null;
    } else {
        $previousAction = $paginator->onFirstPage() ? null : "previousPage('{$pageName}')";
        $nextAction = $paginator->hasMorePages() ? "nextPage('{$pageName}')" : null;
    }
@endphp

<nav
    aria-label="{{ __('filament::components/pagination.label') }}"
    {{
        $attributes->class([
            'fi-pagination',
            'gs-pagination',
            'fi-simple' => $isSimple,
        ])
    }}
>
    <div class="gs-pagination-start">
        @if (! $isSimple)
            <span class="fi-pagination-overview">
                {{
                    trans_choice(
                        'filament::components/pagination.overview',
                        $paginator->total(),
                        [
                            'first' => Number::format($paginator->firstItem() ?? 0),
                            'last' => Number::format($paginator->lastItem() ?? 0),
                            'total' => Number::format($paginator->total()),
                        ],
                    )
                }}
            </span>
        @endif

        @if (count($pageOptions) > 1)
            <label class="gs-pagination-per-page">
                <span class="fi-sr-only">
                    {{ __('filament::components/pagination.fields.records_per_page.label') }}
                </span>

                <x-filament::input.wrapper>
                    <x-filament::input.select :wire:model.live="$currentPageOptionProperty">
                        @foreach ($pageOptions as $option)
                            <option value="{{ $option }}">
                                {{ $option === 'all' ? __('filament::components/pagination.fields.records_per_page.options.all') : $option }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
    </div>

    <ol class="fi-pagination-items">
        @if ($extremeLinks && ! $isSimple)
            <x-filament::pagination.item
                :aria-label="__('filament::components/pagination.actions.first.label')"
                :icon="$isRtl ? Heroicon::OutlinedChevronDoubleRight : Heroicon::OutlinedChevronDoubleLeft"
                :icon-alias="$isRtl ? SupportIconAlias::PAGINATION_FIRST_BUTTON_RTL : SupportIconAlias::PAGINATION_FIRST_BUTTON"
                :disabled="$paginator->onFirstPage()"
                rel="first"
                :wire:click="$paginator->onFirstPage() ? null : 'gotoPage(1, \'' . $pageName . '\')'"
                :wire:key="$this->getId() . '.pagination.first'"
            />
        @endif

        <x-filament::pagination.item
            :aria-label="__('filament::components/pagination.actions.previous.label')"
            :icon="$isRtl ? Heroicon::OutlinedChevronRight : Heroicon::OutlinedChevronLeft"
            :icon-alias="$isRtl ? SupportIconAlias::PAGINATION_PREVIOUS_BUTTON_RTL : SupportIconAlias::PAGINATION_PREVIOUS_BUTTON"
            :disabled="$previousAction === null"
            rel="prev"
            :wire:click="$previousAction"
            :wire:key="$this->getId() . '.pagination.previous'"
        />

        @if (! $isSimple)
            @foreach ($paginator->render()->offsetGet('elements') as $element)
                @if (is_string($element))
                    <x-filament::pagination.item disabled :label="$element" />
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <x-filament::pagination.item
                            :active="$page === $paginator->currentPage()"
                            :aria-label="trans_choice('filament::components/pagination.actions.go_to_page.label', $page, ['page' => Number::format($page)])"
                            :label="Number::format($page)"
                            :wire:click="'gotoPage(' . $page . ', \'' . $pageName . '\')'"
                            :wire:key="$this->getId() . '.pagination.' . $pageName . '.' . $page"
                        />
                    @endforeach
                @endif
            @endforeach
        @endif

        <x-filament::pagination.item
            :aria-label="__('filament::components/pagination.actions.next.label')"
            :icon="$isRtl ? Heroicon::OutlinedChevronLeft : Heroicon::OutlinedChevronRight"
            :icon-alias="$isRtl ? SupportIconAlias::PAGINATION_NEXT_BUTTON_RTL : SupportIconAlias::PAGINATION_NEXT_BUTTON"
            :disabled="$nextAction === null"
            rel="next"
            :wire:click="$nextAction"
            :wire:key="$this->getId() . '.pagination.next'"
        />

        @if ($extremeLinks && ! $isSimple)
            <x-filament::pagination.item
                :aria-label="__('filament::components/pagination.actions.last.label')"
                :icon="$isRtl ? Heroicon::OutlinedChevronDoubleLeft : Heroicon::OutlinedChevronDoubleRight"
                :icon-alias="$isRtl ? SupportIconAlias::PAGINATION_LAST_BUTTON_RTL : SupportIconAlias::PAGINATION_LAST_BUTTON"
                :disabled="! $paginator->hasMorePages()"
                rel="last"
                :wire:click="$paginator->hasMorePages() ? 'gotoPage(' . $paginator->lastPage() . ', \'' . $pageName . '\')' : null"
                :wire:key="$this->getId() . '.pagination.last'"
            />
        @endif
    </ol>
</nav>
