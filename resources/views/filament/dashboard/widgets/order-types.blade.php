{{-- Tipe order: bar porsi omzet + angka (tidak mengandalkan warna saja) --}}
@php($types = $this->getTypes())
@php($empty = collect($types)->sum('order_count') === 0)

<x-filament-widgets::widget>
    <x-filament::section heading="Tipe order" description="Porsi omzet" icon="heroicon-o-shopping-bag" class="h-full">
        @if ($empty)
            <div class="pos-dash-empty">
                <x-filament::icon icon="heroicon-o-shopping-bag" class="pos-dash-empty__icon" />
                <p>Belum ada transaksi</p>
            </div>
        @else
            <div class="space-y-5">
                @foreach ($types as $i => $type)
                    <div wire:key="type-{{ $type['order_type'] }}">
                        <div class="pos-dash-text flex items-baseline justify-between gap-3">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $type['label'] }}</span>
                            <span class="tabular-nums font-semibold text-gray-950 dark:text-white">{{ $type['share'] }}%</span>
                        </div>
                        <progress class="pos-dash-bar pos-dash-bar--lg mt-2 {{ $i > 0 ? 'pos-dash-bar--alt' : '' }}" max="100" value="{{ (int) $type['share'] }}" aria-label="{{ $type['share'] }}% omzet"></progress>
                        <dl class="mt-2 grid grid-cols-3 gap-2 text-xs text-gray-500 dark:text-gray-400">
                            <div><dt>Omzet</dt><dd class="tabular-nums pos-dash-text font-medium text-gray-950 dark:text-white">{{ \App\Support\Money::formatWhole($type['revenue']) }}</dd></div>
                            <div><dt>Transaksi</dt><dd class="tabular-nums pos-dash-text font-medium text-gray-950 dark:text-white">{{ number_format($type['order_count'], 0, ',', '.') }}</dd></div>
                            <div><dt>Rata-rata</dt><dd class="tabular-nums pos-dash-text font-medium text-gray-950 dark:text-white">{{ \App\Support\Money::formatWhole($type['average']) }}</dd></div>
                        </dl>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
