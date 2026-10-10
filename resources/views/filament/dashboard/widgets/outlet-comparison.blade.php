{{-- Perbandingan outlet (hanya "Semua outlet") --}}
<x-filament-widgets::widget>
    @if ($this->isVisible())
        @php($outlets = $this->getOutlets())
        @php($money = fn (string $v): string => \App\Support\Money::format($v))

        <x-filament::section heading="Perbandingan outlet" description="Kontribusi tiap outlet pada periode ini" icon="heroicon-o-building-storefront">
            <div class="pos-dash-table-wrap">
                <table class="pos-dash-table">
                    <thead>
                        <tr>
                            <th>Outlet</th>
                            <th class="text-right">Transaksi</th>
                            <th class="text-right">Omzet</th>
                            <th class="text-right">Rata-rata</th>
                            <th class="text-right">Tren</th>
                            <th class="text-right">Void</th>
                            <th class="pos-dash-table__share">Kontribusi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($outlets as $row)
                            <tr wire:key="outlet-{{ $row['outlet_id'] }}">
                                <td class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                <td class="text-right tabular-nums">{{ number_format($row['order_count'], 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums font-semibold text-gray-950 dark:text-white">{{ $money($row['revenue']) }}</td>
                                <td class="text-right tabular-nums">{{ $money($row['average']) }}</td>
                                <td class="text-right tabular-nums whitespace-nowrap">
                                    @if ($row['change'] === null)
                                        <span class="text-gray-400">–</span>
                                    @else
                                        @php($up = (float) $row['change'] >= 0)
                                        <span class="{{ $up ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                                            {{ $up ? '▲ Naik' : '▼ Turun' }} {{ str_replace('.', ',', ltrim($row['change'], '-')) }}%
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right tabular-nums {{ $row['void_count'] > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">
                                    {{ $row['void_count'] > 0 ? $row['void_count'] . ' (' . $money($row['void_total']) . ')' : '–' }}
                                </td>
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
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
