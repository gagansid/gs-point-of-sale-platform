{{--
    Pemakaian vs batas kuota paket (ADR 0025): halaman Langganan owner & detail bisnis admin.
    $rows dari App\Support\Quotas::usage(). Kartu per kuota: ikon, angka pakai / batas, bar (kuning ≥ 80%, merah bila
    melebihi batas — data lama tetap jalan), status sisa. Tanpa batas = ∞.
--}}
@props(['rows'])

@php
    $number = fn (int $v): string => number_format($v, 0, ',', '.');
    $icons = [
        \App\Support\Quotas::OUTLETS => \Filament\Support\Icons\Heroicon::OutlinedBuildingStorefront,
        \App\Support\Quotas::USERS => \Filament\Support\Icons\Heroicon::OutlinedUsers,
        \App\Support\Quotas::DEVICES => \Filament\Support\Icons\Heroicon::OutlinedDeviceTablet,
        \App\Support\Quotas::PRODUCTS => \Filament\Support\Icons\Heroicon::OutlinedCube,
        \App\Support\Quotas::MEMBERS => \Filament\Support\Icons\Heroicon::OutlinedUserGroup,
    ];
@endphp

<div class="gs-quota-grid">
    @foreach ($rows as $row)
        @php
            $limit = $row['limit'];
            $percent = $limit ? min(100, (int) floor($row['used'] * 100 / max(1, $limit))) : 0;
            $over = $limit !== null && $row['used'] > $limit;
            $full = $limit !== null && ! $over && $row['used'] >= $limit;
            $near = $limit !== null && ! $over && ! $full && $row['used'] * 100 >= $limit * 80;
            $tone = match (true) { $over => 'is-over', $full || $near => 'is-near', $limit === null => 'is-unlimited', default => 'is-ok' };
        @endphp
        <div @class(['gs-quota', $tone]) wire:key="quota-{{ $row['key'] }}">
            <div class="gs-quota__top">
                <span class="gs-quota__icon">
                    <x-filament::icon :icon="$icons[$row['key']] ?? \Filament\Support\Icons\Heroicon::OutlinedSquares2x2" />
                </span>
                <span class="gs-quota__label">{{ $row['name'] }}</span>
            </div>

            <div class="gs-quota__value tabular-nums">
                {{ $number($row['used']) }}<span class="gs-quota__limit"> / {{ $limit !== null ? $number($limit) : '∞' }}</span>
            </div>

            @if ($limit !== null)
                <div class="gs-quota__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}"
                    aria-label="{{ $row['name'] }}: {{ $number($row['used']) }} dari {{ $number($limit) }}">
                    <span style="width: {{ $percent }}%"></span>
                </div>
                <p class="gs-quota__note">
                    @if ($over)
                        Melebihi paket: tidak bisa menambah sampai di bawah batas
                    @elseif ($full)
                        Batas tercapai · upgrade paket atau tambah add-on
                    @else
                        Sisa {{ $number($limit - $row['used']) }} · {{ $percent }}% terpakai
                    @endif
                </p>
            @else
                <div class="gs-quota__bar is-empty"><span></span></div>
                <p class="gs-quota__note">Tanpa batas</p>
            @endif
        </div>
    @endforeach
</div>
