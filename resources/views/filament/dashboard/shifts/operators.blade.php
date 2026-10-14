{{-- Kasir bertugas di shift (log operator, ADR 0017): jam bertugas, transaksi, tunai per kasir. --}}
@php
    $rows = app(\App\Services\Report\ShiftRecap::class)->operators($getRecord());
    $timezone = \App\Support\CurrentOutlet::timezone();
    $clock = fn (?string $iso): string => $iso ? \Carbon\CarbonImmutable::parse($iso)->setTimezone($timezone)->format('H.i') : 'sekarang';
@endphp

@if ($rows === [])
    <p class="gs-detail-empty">Belum ada kasir yang tercatat</p>
@else
    <table class="gs-detail-table">
        <thead>
            <tr>
                <th>Kasir</th>
                <th>Jam bertugas</th>
                <th class="is-num">Transaksi</th>
                <th class="is-num">Tunai</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="gs-detail-name">{{ $row['name'] }}</td>
                    <td>{{ collect($row['periods'])->map(fn ($p) => $clock($p['started_at']).'–'.$clock($p['ended_at']))->implode(', ') ?: '–' }}</td>
                    <td class="is-num">{{ $row['order_count'] }}</td>
                    <td class="is-num is-strong">{{ \App\Support\Money::format($row['cash_sales']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
