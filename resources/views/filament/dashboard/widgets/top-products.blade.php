{{-- Produk terlaris (stat-widget.md / table.md: angka rata kanan, tabular) --}}
<x-filament-widgets::widget>
    <x-filament::section heading="Produk terlaris (7 hari)" icon="heroicon-o-trophy">
        @php($products = $this->getProducts())

        @if ($products === [])
            <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Belum ada data pada periode ini</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Produk</th>
                        <th class="pb-2 text-right">Qty</th>
                        <th class="pb-2 text-right">Omzet</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($products as $row)
                        <tr>
                            <td class="py-2 font-medium text-gray-950 dark:text-white">{{ $row['product_name'] }}</td>
                            <td class="py-2 text-right tabular-nums">{{ number_format($row['qty'], 0, ',', '.') }}</td>
                            <td class="py-2 text-right tabular-nums">{{ \App\Support\Money::format($row['revenue']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
