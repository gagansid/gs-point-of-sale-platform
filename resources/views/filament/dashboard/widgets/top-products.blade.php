{{-- Produk terlaris / paling tidak laku (table.md: angka rata kanan, tabular) --}}
@php($products = $this->getProducts())

<x-filament-widgets::widget>
    <x-filament::section
        :heading="$mode === 'slow' ? 'Produk paling tidak laku' : 'Produk terlaris'"
        :description="$mode === 'slow' ? 'Pertimbangkan promo atau hapus dari menu' : 'Top 10 berdasarkan omzet'"
        :icon="$mode === 'slow' ? 'heroicon-o-arrow-trending-down' : 'heroicon-o-trophy'"
    >
        <x-slot name="afterHeader">
            <x-filament::tabs contained>
                <x-filament::tabs.item :active="$mode === 'top'" wire:click="setMode('top')">Terlaris</x-filament::tabs.item>
                <x-filament::tabs.item :active="$mode === 'slow'" wire:click="setMode('slow')">Kurang laku</x-filament::tabs.item>
            </x-filament::tabs>
        </x-slot>

        @if ($products === [])
            <div class="pos-dash-empty">
                <x-filament::icon icon="heroicon-o-cube" class="pos-dash-empty__icon" />
                <p>Belum ada data pada periode ini</p>
            </div>
        @else
            <div class="pos-dash-table-wrap">
                <table class="pos-dash-table">
                    <thead>
                        <tr>
                            <th class="w-8">#</th>
                            <th>Produk</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Omzet</th>
                            <th class="pos-dash-table__share">Kontribusi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($products as $i => $row)
                            <tr wire:key="product-{{ $row['product_id'] }}">
                                <td class="text-gray-400">{{ $i + 1 }}</td>
                                <td class="font-medium text-gray-950 dark:text-white">{{ $row['product_name'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($row['qty'], 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums">{{ \App\Support\Money::format($row['revenue']) }}</td>
                                <td class="pos-dash-table__share">
                                    <div class="pos-dash-bar" role="img" aria-label="{{ str_replace('.', ',', $row['share']) }}% dari total">
                                        <span class="pos-dash-bar__fill" style="width: {{ min((float) $row['share'], 100) }}%"></span>
                                    </div>
                                    <span class="tabular-nums">{{ str_replace('.', ',', $row['share']) }}%</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
