{{-- Kas masuk/keluar shift (ADR 0017): jam, alasan, pencatat & approver, nominal. --}}
@php
    $rows = app(\App\Services\Report\ShiftRecap::class)->movements($getRecord());
    $timezone = \App\Support\CurrentOutlet::timezone();
@endphp

@if ($rows === [])
    <p class="gs-detail-empty">Tidak ada kas masuk/keluar</p>
@else
    <table class="gs-detail-table">
        <thead>
            <tr>
                <th>Jam</th>
                <th>Alasan</th>
                <th>Oleh</th>
                <th class="is-num">Nominal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ \Carbon\CarbonImmutable::parse($row['created_at'])->setTimezone($timezone)->format('H.i') }}</td>
                    <td class="gs-detail-name">{{ $row['reason_label'] }}@if ($row['note'])<br><span class="gs-detail-empty">{{ $row['note'] }}</span>@endif</td>
                    <td>{{ $row['user'] }}@if ($row['approved_by'])<br><span class="gs-detail-empty">disetujui {{ $row['approved_by'] }}</span>@endif</td>
                    <td class="is-num is-strong">{{ $row['type'] === 'out' ? '−' : '+' }}{{ \App\Support\Money::format($row['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
