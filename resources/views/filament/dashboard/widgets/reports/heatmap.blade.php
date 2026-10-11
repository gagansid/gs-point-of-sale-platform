{{-- Heatmap hari × jam: level intensitas lewat kelas CSS, angka di tooltip & sr-only --}}
@php($map = $this->getHeatmap())
@php($days = \App\Filament\Dashboard\Widgets\Reports\HeatmapWidget::DAYS)

<x-filament-widgets::widget>
    <x-filament::section
        heading="Kepadatan per hari & jam"
        :description="$map['busiest'] ? 'Tersibuk ' . $days[$map['busiest']['day']] . ' ' . sprintf('%02d.00', $map['busiest']['hour']) . ' · ' . $map['busiest']['count'] . ' transaksi' : 'Belum ada data pada periode ini'"
        icon="heroicon-o-calendar-days"
    >
        <div class="pos-dash-table-wrap">
            <table class="pos-heatmap">
                <caption class="sr-only">Jumlah transaksi per hari dan jam</caption>
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Hari</span></th>
                        @foreach ($map['hours'] as $hour)
                            <th scope="col">{{ sprintf('%02d', $hour) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $day => $label)
                        <tr>
                            <th scope="row">{{ $label }}</th>
                            @foreach ($map['hours'] as $hour)
                                @php($count = $map['grid'][$day][$hour] ?? 0)
                                <td class="pos-heatmap__cell pos-heatmap__cell--{{ \App\Filament\Dashboard\Widgets\Reports\HeatmapWidget::level($count, $map['max']) }}"
                                    title="{{ $label }} {{ sprintf('%02d.00', $hour) }}: {{ $count }} transaksi">
                                    <span class="sr-only">{{ $count }}</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="pos-heatmap__legend" aria-hidden="true">
            <span>Sepi</span>
            @foreach (range(0, 4) as $level)
                <span class="pos-heatmap__cell pos-heatmap__cell--{{ $level }}"></span>
            @endforeach
            <span>Ramai</span>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
