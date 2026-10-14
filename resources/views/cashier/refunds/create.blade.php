{{-- /cashier/refunds/{order}: pilih item & qty yang diretur (ADR 0018). Nilai final dihitung server. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Retur {{ $order->order_number }}" active="orders">
    <div class="cashier-grid-2">
        <section class="cashier-card">
            <div class="cashier-card-head">
                <h1>Retur {{ $order->order_number }}</h1>
                <a href="{{ route('cashier.refunds.search') }}" class="site-btn site-btn-outline">Cari lain</a>
            </div>
            <p class="cashier-muted">{{ $order->completed_at?->setTimezone($timezone)->format('d-m-Y, H:i') }} · Total {{ $money($order->grand_total) }}@if (\App\Support\Money::isPositive($order->refunded_total)) · sudah diretur {{ $money($order->refunded_total) }}@endif</p>

            @if ($order->isFullyRefunded())
                <div class="site-alert site-alert-warning" role="alert">Semua item transaksi ini sudah diretur.</div>
            @else
                <form method="POST" action="{{ route('cashier.refunds.store', $order->id) }}" class="site-form" novalidate>
                    @csrf
                    <input type="hidden" name="id" value="{{ $refundId }}">
                    <table class="cashier-table">
                        <thead><tr><th>Item</th><th class="num">Terjual</th><th class="num">Retur</th></tr></thead>
                        <tbody>
                            @foreach ($order->items as $i => $item)
                                @php($left = $item->qty - $item->refunded_qty)
                                <tr>
                                    <td>
                                        {{ $item->product_name }}
                                        @if ($item->options->isNotEmpty())<br><small class="cashier-muted">{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif
                                        @if ($item->refunded_qty > 0)<br><small class="cashier-muted">{{ $item->refunded_qty }} sudah diretur</small>@endif
                                    </td>
                                    <td class="num">{{ $item->qty }}</td>
                                    <td class="num">
                                        <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item->id }}">
                                        <input type="number" name="items[{{ $i }}][qty]" min="0" max="{{ $left }}" step="1" inputmode="numeric"
                                            value="{{ old("items.$i.qty", 0) }}" class="cashier-qty" aria-label="Qty retur {{ $item->product_name }}" @disabled($left === 0)>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @error('items') <p class="site-error">{{ $message }}</p> @enderror

                    <div class="site-field">
                        <label for="reason">Alasan<span class="site-required" aria-hidden="true">*</span></label>
                        <input id="reason" name="reason" type="text" maxlength="255" value="{{ old('reason') }}" placeholder="Produk rusak" required>
                        @error('reason') <p class="site-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="cashier-tabs" role="radiogroup" aria-label="Cara pengembalian">
                        @foreach (\App\Enums\RefundMethod::cases() as $method)
                            <label><input type="radio" name="method" value="{{ $method->value }}" @checked(old('method', 'cash') === $method->value)><span>{{ $method->getLabel() }}</span></label>
                        @endforeach
                    </div>
                    <label class="site-check">
                        <input type="hidden" name="restock" value="0">
                        <input type="checkbox" name="restock" value="1" @checked(old('restock', '1') === '1')> Kembalikan barang ke stok
                    </label>
                    @include('cashier.partials.approver', ['approvers' => $approvers, 'id' => 'refund'])
                    <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block" data-confirm-step="Klik lagi untuk mencatat retur">Catat retur</button>
                </form>
            @endif
        </section>

        <section class="cashier-card">
            <h2>Riwayat retur</h2>
            @if ($order->refunds->isEmpty())
                <p class="cashier-muted">Belum ada retur untuk transaksi ini.</p>
            @else
                <table class="cashier-table">
                    <thead><tr><th>Waktu</th><th>Item</th><th class="num">Dikembalikan</th></tr></thead>
                    <tbody>
                        @foreach ($order->refunds as $refund)
                            <tr>
                                <td>{{ $refund->created_at->setTimezone($timezone)->format('d-m-Y, H:i') }}<br><small class="cashier-muted">{{ $refund->user?->name }} · {{ $refund->method->getLabel() }}</small></td>
                                <td>{{ $refund->items->map(fn ($i) => $i->qty.'× '.$i->product_name)->implode(', ') }}<br><small class="cashier-muted">“{{ $refund->reason }}”</small></td>
                                <td class="num">{{ $money($refund->payout_amount) }}@if ($refund->points_returned > 0)<br><small class="cashier-muted">+{{ $refund->points_returned }} poin</small>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</x-cashier.layout>
