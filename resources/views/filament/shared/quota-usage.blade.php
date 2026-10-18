{{--
    Pemakaian vs batas kuota paket (ADR 0025): halaman Langganan owner & detail bisnis admin.
    $rows dari App\Support\Quotas::usage(). Bar: kuning ≥ 80%, merah bila melebihi batas (data lama tetap jalan).
--}}
@props(['rows'])

@php($number = fn (int $v): string => number_format($v, 0, ',', '.'))

<div class="gs-quota-grid">
    @foreach ($rows as $row)
        @php($limit = $row['limit'])
        @php($percent = $limit ? min(100, (int) floor($row['used'] * 100 / $limit)) : 0)
        @php($over = $limit !== null && $row['used'] > $limit)
        @php($near = $limit !== null && ! $over && $row['used'] * 100 >= $limit * 80)
        <div class="gs-quota" wire:key="quota-{{ $row['key'] }}">
            <div class="gs-quota__head">
                <span class="gs-quota__label">{{ $row['name'] }}</span>
                <span @class(['gs-quota__value tabular-nums', 'is-near' => $near, 'is-over' => $over])>
                    {{ $number($row['used']) }}{{ $limit !== null ? ' / '.$number($limit) : '' }}
                </span>
            </div>
            @if ($limit !== null)
                <progress @class(['gs-quota__bar', 'is-near' => $near, 'is-over' => $over]) max="100" value="{{ $percent }}"
                    aria-label="{{ $row['name'] }}: {{ $number($row['used']) }} dari {{ $number($limit) }}"></progress>
                @if ($over)
                    <p class="gs-quota__note is-over">Melebihi paket: tidak bisa menambah sampai di bawah batas</p>
                @endif
            @else
                <p class="gs-quota__note">Tanpa batas</p>
            @endif
        </div>
    @endforeach
</div>
