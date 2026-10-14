{{-- Riwayat retur sebagian order (ADR 0018). --}}
@php
    $refunds = $getRecord()->refunds()->with(['items', 'user:id,name', 'approver:id,name'])->get();
    $timezone = \App\Support\CurrentOutlet::timezone();
@endphp

<table class="gs-detail-table">
    <thead>
        <tr>
            <th>Waktu</th>
            <th>Item</th>
            <th class="is-num">Nilai</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($refunds as $refund)
            <tr>
                <td>{{ $refund->created_at->setTimezone($timezone)->format('j M Y, H.i') }}<br><span class="gs-detail-empty">{{ $refund->user?->name }}@if ($refund->approver) · disetujui {{ $refund->approver->name }}@endif</span></td>
                <td class="gs-detail-name">{{ $refund->items->map(fn ($i) => $i->qty.'× '.$i->product_name)->implode(', ') }}<br><span class="gs-detail-empty">“{{ $refund->reason }}” · {{ $refund->method->getLabel() }}</span></td>
                <td class="is-num is-strong">−{{ \App\Support\Money::format($refund->amount) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
