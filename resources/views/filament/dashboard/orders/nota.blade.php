{{--
    Detail order bergaya nota: info transaksi → item → total → pembayaran (satu kartu).
    Nilai dari snapshot order (harga & tarif saat transaksi). Teks pengguna di-escape.
--}}
@php
    use App\Support\Money;
    use Filament\Support\Facades\FilamentTimezone;

    /** @var \App\Models\Order $order */
    $order = $getRecord();
    $rp = fn (string $value): string => Money::format($value);
    $rate = fn (string $value): string => rtrim(rtrim($value, '0'), '.');
    $methods = $order->payments->map(fn ($p) => $p->method?->name ?? $p->category->getLabel())->unique()->join(', ');
    $time = $order->created_at->copy()->setTimezone(FilamentTimezone::get());
@endphp

<div class="gs-nota">
    <header class="gs-nota-head">
        <div>
            <div class="gs-nota-number">{{ $order->order_number }}</div>
            <div class="gs-nota-time">{{ $time->translatedFormat('l, j F Y · H.i') }}</div>
        </div>
        <x-filament::badge :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
    </header>

    <dl class="gs-nota-meta">
        <div><dt>Kasir</dt><dd>{{ $order->user?->name ?? '—' }}</dd></div>
        <div><dt>Tipe</dt><dd>{{ $order->order_type->getLabel() }}{{ filled($order->table_label) ? ' · '.$order->table_label : '' }}</dd></div>
        <div><dt>Pembayaran</dt><dd>{{ $methods !== '' ? $methods : 'Belum dibayar' }}</dd></div>
        @if (filled($order->notes))
            <div class="is-wide"><dt>Catatan</dt><dd>{{ $order->notes }}</dd></div>
        @endif
    </dl>

    <div class="gs-nota-section">
        <div class="gs-nota-caption">Item <span>{{ $order->items->sum('qty') }}</span></div>
        @foreach ($order->items as $item)
            <div class="gs-nota-line">
                <div class="gs-nota-line-main">
                    <div class="gs-nota-name">{{ $item->product_name }}</div>
                    <div class="gs-nota-sub">
                        {{ $item->qty }} × {{ $rp(Money::add($item->unit_price, $item->options_total)) }}
                        @php($options = $item->options->pluck('option_name')->join(', '))
                        @if ($options !== '') · {{ $options }} @endif
                        @if (Money::isPositive($item->discount)) · Diskon {{ $rp($item->discount) }} @endif
                    </div>
                    @if (filled($item->notes))
                        <div class="gs-nota-sub">“{{ $item->notes }}”</div>
                    @endif
                </div>
                <div class="gs-nota-amount">{{ $rp($item->line_total) }}</div>
            </div>
        @endforeach
    </div>

    <div class="gs-nota-section gs-nota-totals">
        <div class="gs-nota-row"><span>Subtotal</span><span>{{ $rp($order->subtotal) }}</span></div>
        @if (Money::isPositive($order->discount_total))
            <div class="gs-nota-row"><span>Diskon</span><span>-{{ $rp($order->discount_total) }}</span></div>
        @endif
        @if (Money::isPositive($order->service_total))
            <div class="gs-nota-row"><span>Service {{ $rate($order->service_rate) }}%</span><span>{{ $rp($order->service_total) }}</span></div>
        @endif
        @if (Money::isPositive($order->tax_total))
            <div class="gs-nota-row"><span>Pajak {{ $rate($order->tax_rate) }}%{{ $order->tax_inclusive ? ' (termasuk)' : '' }}</span><span>{{ $rp($order->tax_total) }}</span></div>
        @endif
        @if (Money::compare($order->rounding, '0') !== 0)
            <div class="gs-nota-row"><span>Pembulatan</span><span>{{ $rp($order->rounding) }}</span></div>
        @endif
        <div class="gs-nota-row gs-nota-grand"><span>Total</span><span>{{ $rp($order->grand_total) }}</span></div>
    </div>

    <div class="gs-nota-section">
        @forelse ($order->payments as $payment)
            <div class="gs-nota-row">
                <span>
                    {{ $payment->method?->name ?? $payment->category->getLabel() }}
                    @if (filled($payment->reference)) <span class="gs-nota-muted">· Ref. {{ $payment->reference }}</span> @endif
                    @if ($payment->status !== \App\Enums\PaymentStatus::Paid) <span class="gs-nota-muted">· {{ $payment->status->getLabel() }}</span> @endif
                </span>
                <span>{{ $rp($payment->tendered) }}</span>
            </div>
        @empty
            <div class="gs-nota-row gs-nota-muted"><span>Belum ada pembayaran</span><span></span></div>
        @endforelse
        <div class="gs-nota-row gs-nota-muted"><span>Kembalian</span><span>{{ $rp($order->change_total) }}</span></div>
    </div>
</div>
