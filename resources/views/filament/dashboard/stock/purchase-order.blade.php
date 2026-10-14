{{-- Detail purchase order (ADR 0021): info, item dipesan vs diterima, stok masuk terkait. --}}
@php
    use App\Support\Money;
    /** @var \App\Models\PurchaseOrder $record */
    $record = $getRecord();
    $rp = fn (?string $v): string => $v === null ? '—' : Money::format($v);
    $tz = \App\Support\CurrentOutlet::timezone();
    $receipts = $record->receipts()->get(['id', 'number', 'status', 'received_on', 'total_cost']);
@endphp
<div class="gs-nota">
    <dl class="gs-nota-meta">
        <div><dt>Nomor</dt><dd>{{ $record->number }}</dd></div>
        <div><dt>Status</dt><dd>{{ $record->status->getLabel() }}</dd></div>
        <div><dt>Outlet</dt><dd>{{ $record->outlet?->name }}</dd></div>
        <div><dt>Supplier</dt><dd>{{ $record->supplier?->name }}</dd></div>
        <div><dt>Tanggal pesan</dt><dd>{{ $record->ordered_on->locale('id')->translatedFormat('j M Y') }}</dd></div>
        <div><dt>Perkiraan datang</dt><dd>{{ $record->expected_on?->locale('id')->translatedFormat('j M Y') ?? '—' }}</dd></div>
        @if ($record->sent_at)
            <div><dt>Dikirim</dt><dd>{{ $record->sent_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }} · {{ $record->sentBy?->name }}</dd></div>
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
                    <th class="text-right">Dipesan</th>
                    <th class="text-right">Diterima</th>
                    <th class="text-right">Sisa</th>
                    <th class="text-right">Harga beli</th>
                    <th class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $item)
                    <tr wire:key="poi-{{ $item->id }}">
                        <td>{{ $item->product_name }}</td>
                        <td class="text-right tabular-nums">{{ number_format($item->qty, 0, ',', '.') }}</td>
                        <td class="text-right tabular-nums">{{ number_format($item->received_qty, 0, ',', '.') }}</td>
                        <td class="text-right tabular-nums">{{ number_format($item->remaining(), 0, ',', '.') }}</td>
                        <td class="text-right tabular-nums">{{ $rp($item->unit_cost) }}</td>
                        <td class="text-right tabular-nums">{{ $rp($item->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th class="text-right tabular-nums">{{ number_format($record->items->sum('qty'), 0, ',', '.') }}</th>
                    <th class="text-right tabular-nums">{{ number_format($record->items->sum('received_qty'), 0, ',', '.') }}</th>
                    <th></th>
                    <th></th>
                    <th class="text-right tabular-nums">{{ $rp($record->total_cost) }}</th>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($receipts->isNotEmpty())
        <div class="gs-nota-section">
        <p class="gs-nota-caption">Stok masuk dari PO ini</p>
        <ul class="space-y-1 text-sm">
            @foreach ($receipts as $receipt)
                <li>
                    <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Dashboard\Resources\StockReceipts\StockReceiptResource::getUrl($receipt->isDraft() ? 'edit' : 'view', ['record' => $receipt]) }}">{{ $receipt->number }}</a>
                    · {{ $receipt->received_on->locale('id')->translatedFormat('j M Y') }} · {{ $receipt->status->getLabel() }} · {{ $rp($receipt->total_cost) }}
                </li>
            @endforeach
        </ul>
        </div>
    @endif
</div>
