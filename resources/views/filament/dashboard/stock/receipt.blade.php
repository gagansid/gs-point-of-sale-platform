{{-- Detail stok masuk (ADR 0015): info dokumen, item, harga beli, modal sebelum → sesudah posting. --}}
@php
    use App\Support\Money;
    /** @var \App\Models\StockReceipt $record */
    $record = $getRecord();
    $rp = fn (?string $v): string => $v === null ? '—' : Money::format($v);
    $tz = \App\Support\CurrentOutlet::timezone();
@endphp
<div class="gs-nota">
    <dl class="gs-nota-meta">
        <div><dt>Nomor</dt><dd>{{ $record->number }}</dd></div>
        <div><dt>Status</dt><dd>{{ $record->status->getLabel() }}</dd></div>
        <div><dt>Outlet</dt><dd>{{ $record->outlet?->name }}</dd></div>
        <div><dt>Tanggal terima</dt><dd>{{ $record->received_on->locale('id')->translatedFormat('j M Y') }}</dd></div>
        <div><dt>Supplier</dt><dd>{{ $record->supplier?->name ?? '—' }}</dd></div>
        <div><dt>Nomor nota</dt><dd>{{ $record->invoice_number ?? '—' }}</dd></div>
        @if ($record->purchaseOrder)
            {{-- Stok masuk dari purchase order (ADR 0021) --}}
            <div><dt>Purchase order</dt><dd><a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ \App\Filament\Dashboard\Resources\PurchaseOrders\PurchaseOrderResource::getUrl('view', ['record' => $record->purchaseOrder]) }}">{{ $record->purchaseOrder->number }}</a></dd></div>
        @endif
        @if ($record->posted_at)
            <div><dt>Diposting</dt><dd>{{ $record->posted_at->setTimezone($tz)->locale('id')->translatedFormat('j M Y, H.i') }} · {{ $record->postedBy?->name }}</dd></div>
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
                    <th class="text-right">Qty</th>
                    <th class="text-right">Harga beli</th>
                    <th class="text-right">Subtotal</th>
                    <th class="text-right">Modal sebelum → sesudah</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $item)
                    <tr wire:key="ri-{{ $item->id }}">
                        <td>{{ $item->product_name }}</td>
                        <td class="text-right tabular-nums">{{ number_format($item->qty, 0, ',', '.') }}</td>
                        <td class="text-right tabular-nums">{{ $rp($item->unit_cost) }}</td>
                        <td class="text-right tabular-nums">{{ $rp($item->line_total) }}</td>
                        <td class="text-right tabular-nums">{{ $item->cost_after !== null ? $rp($item->cost_before).' → '.$rp($item->cost_after) : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>Total</th>
                    <th class="text-right tabular-nums">{{ number_format($record->items->sum('qty'), 0, ',', '.') }}</th>
                    <th></th>
                    <th class="text-right tabular-nums">{{ $rp($record->total_cost) }}</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
