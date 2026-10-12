{{-- Detail transfer antar outlet (ADR 0015): rute, waktu kirim/terima, qty dikirim vs diterima. --}}
@php
    /** @var \App\Models\StockTransfer $record */
    $record = $getRecord();
    $tz = \App\Support\CurrentOutlet::timezone();
    $at = fn ($time): string => $time->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i');
@endphp
<div class="gs-nota">
    <dl class="gs-nota-meta">
        <div><dt>Nomor</dt><dd>{{ $record->number }}</dd></div>
        <div><dt>Status</dt><dd>{{ $record->status->getLabel() }}</dd></div>
        <div><dt>Dari</dt><dd>{{ $record->fromOutlet?->name }}</dd></div>
        <div><dt>Ke</dt><dd>{{ $record->toOutlet?->name }}</dd></div>
        @if ($record->sent_at)
            <div><dt>Dikirim</dt><dd>{{ $at($record->sent_at) }} · {{ $record->sentBy?->name }}</dd></div>
        @endif
        @if ($record->received_at)
            <div><dt>Diterima</dt><dd>{{ $at($record->received_at) }} · {{ $record->receivedBy?->name }}</dd></div>
        @endif
        @if (filled($record->notes))
            <div><dt>Catatan</dt><dd>{{ $record->notes }}</dd></div>
        @endif
    </dl>

    <div class="pos-dash-table-wrap">
        <table class="pos-dash-table">
            <thead>
                <tr>
                    <th>Produk</th>
                    <th class="text-right">Dikirim</th>
                    <th class="text-right">Diterima</th>
                    <th class="text-right">Selisih</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $item)
                    @php($gap = $item->qty_received !== null ? $item->qty_received - $item->qty_sent : null)
                    <tr wire:key="ti-{{ $item->id }}">
                        <td>{{ $item->product_name }}</td>
                        <td class="text-right tabular-nums">{{ number_format($item->qty_sent, 0, ',', '.') }}</td>
                        <td class="text-right tabular-nums">{{ $item->qty_received !== null ? number_format($item->qty_received, 0, ',', '.') : '—' }}</td>
                        <td @class(['text-right tabular-nums', 'text-danger-600 dark:text-danger-400' => ($gap ?? 0) < 0])>{{ $gap === null ? '—' : ($gap === 0 ? '0' : number_format($gap, 0, ',', '.')) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
