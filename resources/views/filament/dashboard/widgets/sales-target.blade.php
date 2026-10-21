{{-- Target omzet (Q56): progres hari ini & bulan berjalan per outlet --}}
<x-filament-widgets::widget>
    @php($targets = $this->getTargets())
    @php($money = fn (?string $v): string => \App\Support\Money::formatWhole((string) $v))
    @php($pct = fn (string $v): string => str_replace('.', ',', $v).'%')

    @if ($targets === [])
        @if ($this->canSetTargets())
            <x-filament::section heading="Target omzet" icon="heroicon-o-flag" compact>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Belum ada target. Atur target harian & bulanan per outlet agar progres penjualan terlihat di sini.
                    <x-filament::link :href="$this->settingsUrl()" size="sm">Atur di Profil outlet</x-filament::link>
                </p>
            </x-filament::section>
        @endif
    @else
        <x-filament::section heading="Target omzet" description="Hari ini & bulan berjalan, tidak mengikuti filter periode" icon="heroicon-o-flag">
            <div class="pos-target-grid">
                @foreach ($targets as $row)
                    <div class="pos-target" wire:key="target-{{ $row['outlet_id'] }}">
                        {{-- Nama outlet perlu saat "Semua outlet" walau hanya satu yang punya target --}}
                        @if (count($targets) > 1 || \App\Support\CurrentOutlet::selectedId() === null)
                            <p class="pos-target__outlet">{{ $row['name'] }}</p>
                        @endif

                        @foreach ([
                            ['Hari ini', $row['daily_revenue'], $row['daily_target'], $row['daily_percent'], null],
                            ['Bulan ini', $row['monthly_revenue'], $row['monthly_target'], $row['monthly_percent'], $row['month_pace']],
                        ] as [$label, $revenue, $target, $percent, $pace])
                            @continue($percent === null)
                            @php($reached = (float) $percent >= 100)
                            @php($behind = $pace !== null && ! $reached && (float) $percent < (float) $pace)
                            <div class="pos-target__row">
                                <div class="pos-target__head">
                                    <span class="pos-target__label">{{ $label }}</span>
                                    <span @class(['pos-target__pct tabular-nums', 'is-reached' => $reached, 'is-behind' => $behind])>{{ $pct($percent) }}</span>
                                </div>
                                <progress @class(['pos-target__bar', 'is-reached' => $reached, 'is-behind' => $behind]) max="100" value="{{ min((float) $percent, 100) }}"
                                    aria-label="{{ $label }}: {{ $pct($percent) }} dari target"></progress>
                                <p class="pos-target__meta tabular-nums">
                                    {{ $money($revenue) }} dari {{ $money($target) }}
                                    @if ($reached)
                                        · Tercapai
                                    @elseif ($pace !== null)
                                        · {{ $behind ? 'Di bawah jalur' : 'Sesuai jalur' }} ({{ $pct($pace) }} bulan berlalu)
                                    @else
                                        · Kurang {{ $money(\App\Support\Money::sub($target, $revenue)) }}
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
