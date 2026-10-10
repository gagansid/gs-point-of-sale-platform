{{-- Rekap penjualan shift per metode bayar (ShiftSummary) sebagai tabel ringkas. --}}
@php
    $rows = app(\App\Services\Shift\ShiftSummary::class)->for($getRecord())['payment_methods'];
@endphp

@if ($rows === [])
    <p class="gs-detail-empty">Belum ada pembayaran di shift ini</p>
@else
    <table class="gs-detail-table">
        <thead>
            <tr>
                <th>Metode</th>
                <th class="is-num">Transaksi</th>
                <th class="is-num">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td class="gs-detail-name">{{ $row['name'] }}</td>
                    <td class="is-num">{{ $row['count'] }}</td>
                    <td class="is-num is-strong">{{ \App\Support\Money::format((string) $row['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
