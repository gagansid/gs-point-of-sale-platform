{{-- Baris total di <tfoot> tabel laporan (L5): satu sel per kolom yang tampil, ikut perataan &
     breakpoint kolomnya. $totals: nama kolom => teks terformat; kolom lain dikosongkan. --}}
@php
    use Filament\Support\Enums\Alignment;
@endphp

@foreach ($columns as $column)
    @php
        $alignment = $column->getAlignment();
        $alignment = $alignment instanceof Alignment ? $alignment->value : 'start';
    @endphp

    <{{ $loop->first ? 'th' : 'td' }}
        @if ($loop->first) scope="row" @endif
        @class([
            'fi-ta-cell fi-ta-summary-row-heading-cell text-sm font-semibold text-gray-950 dark:text-white tabular-nums',
            "fi-align-{$alignment}",
            filled($column->getHiddenFrom()) ? $column->getHiddenFrom().':fi-hidden' : '',
            filled($column->getVisibleFrom()) ? $column->getVisibleFrom().':fi-visible' : '',
        ])
    >
        {{ $loop->first ? $label : ($totals[$column->getName()] ?? '') }}
    </{{ $loop->first ? 'th' : 'td' }}>
@endforeach
