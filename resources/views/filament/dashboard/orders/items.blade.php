{{-- Item order (snapshot nama & harga saat transaksi) sebagai tabel ringkas. --}}
@php
    /** @var \App\Models\Order $order */
    $order = $getRecord();
    $rp = fn (string $value): string => \App\Support\Money::format($value);
@endphp

<table class="gs-detail-table">
    <thead>
        <tr>
            <th>Produk</th>
            <th class="is-num">Qty</th>
            <th class="is-num">Harga</th>
            <th class="is-num">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>
                    <div class="gs-detail-name">{{ $item->product_name }}</div>
                    @php($options = $item->options->pluck('option_name')->join(', '))
                    @if ($options !== '' || filled($item->notes) || \App\Support\Money::isPositive($item->discount))
                        <div class="gs-detail-sub">
                            {{ collect([$options ?: null, filled($item->notes) ? '“'.$item->notes.'”' : null, \App\Support\Money::isPositive($item->discount) ? 'Diskon '.$rp($item->discount) : null])->filter()->join(' · ') }}
                        </div>
                    @endif
                </td>
                <td class="is-num">{{ $item->qty }}</td>
                <td class="is-num">{{ $rp(\App\Support\Money::add($item->unit_price, $item->options_total)) }}</td>
                <td class="is-num is-strong">{{ $rp($item->line_total) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
