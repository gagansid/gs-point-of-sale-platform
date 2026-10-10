{{-- Riwayat pendapatan harian (table.md: header bisa diurutkan, angka rata kanan, baris total) --}}
@php($history = $this->getHistory())
@php($money = fn (string $v): string => \App\Support\Money::format($v))
@php($columns = \App\Filament\Dashboard\Widgets\DailyRevenueWidget::COLUMNS)

<x-filament-widgets::widget>
    <x-filament::section
        heading="Riwayat pendapatan"
        :description="'Rincian per hari · rata-rata transaksi ' . $money($history['total']['average'])"
        icon="heroicon-o-table-cells"
    >
        @if ($history['total']['order_count'] === 0 && $history['total']['void_count'] === 0)
            <div class="pos-dash-empty">
                <x-filament::icon icon="heroicon-o-banknotes" class="pos-dash-empty__icon" />
                <p>Belum ada transaksi pada periode ini</p>
            </div>
        @else
            <div class="pos-dash-table-wrap pos-dash-table-wrap--scroll">
                <table class="pos-dash-table pos-dash-table--sticky">
                    <thead>
                        <tr>
                            @foreach ($columns as $key => $label)
                                <th class="{{ $key === 'date' ? '' : 'text-right' }}"
                                    aria-sort="{{ $sortColumn === $key ? ($sortDirection === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" wire:click="sortBy('{{ $key }}')" class="pos-dash-sort {{ $sortColumn === $key ? 'pos-dash-sort--active' : '' }}">
                                        {{ $label }}
                                        <x-filament::icon
                                            :icon="$sortColumn === $key ? ($sortDirection === 'asc' ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down') : 'heroicon-m-chevron-up-down'"
                                            class="h-4 w-4"
                                        />
                                    </button>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history['rows'] as $row)
                            <tr wire:key="day-{{ $row['date'] }}" class="{{ $row['order_count'] === 0 && $row['void_count'] === 0 ? 'pos-dash-table__row--muted' : '' }}">
                                <td class="whitespace-nowrap font-medium text-gray-950 dark:text-white">
                                    {{ \Carbon\CarbonImmutable::parse($row['date'])->locale('id')->translatedFormat('D, j M Y') }}
                                </td>
                                <td class="text-right tabular-nums">{{ number_format($row['order_count'], 0, ',', '.') }}</td>
                                <td class="text-right tabular-nums font-semibold text-gray-950 dark:text-white">{{ $money($row['revenue']) }}</td>
                                <td class="text-right tabular-nums">{{ $money($row['discount_total']) }}</td>
                                <td class="text-right tabular-nums">{{ $money($row['service_total']) }}</td>
                                <td class="text-right tabular-nums">{{ $money($row['tax_total']) }}</td>
                                <td class="text-right tabular-nums">{{ $money($row['rounding_total']) }}</td>
                                <td class="text-right tabular-nums {{ $row['void_count'] > 0 ? 'text-warning-600 dark:text-warning-400' : '' }}">
                                    {{ $row['void_count'] > 0 ? $money($row['void_total']) . ' (' . $row['void_count'] . ')' : '–' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="text-right tabular-nums">{{ number_format($history['total']['order_count'], 0, ',', '.') }}</th>
                            <th class="text-right tabular-nums">{{ $money($history['total']['revenue']) }}</th>
                            <th class="text-right tabular-nums">{{ $money($history['total']['discount_total']) }}</th>
                            <th class="text-right tabular-nums">{{ $money($history['total']['service_total']) }}</th>
                            <th class="text-right tabular-nums">{{ $money($history['total']['tax_total']) }}</th>
                            <th class="text-right tabular-nums">{{ $money($history['total']['rounding_total']) }}</th>
                            <th class="text-right tabular-nums">{{ $history['total']['void_count'] > 0 ? $money($history['total']['void_total']) . ' (' . $history['total']['void_count'] . ')' : '–' }}</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
