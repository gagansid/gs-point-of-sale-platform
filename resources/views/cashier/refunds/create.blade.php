{{-- /cashier/refunds/{order}: pilih item & qty yang diretur (ADR 0018). Nilai final dihitung server. Gaya halaman kasir Q130. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Retur {{ $order->order_number }}" active="orders">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <a href="{{ route('cashier.orders.show', $order->id) }}" class="pos-back">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Detail transaksi</a>
                <h1>Retur {{ $order->order_number }}</h1>
                <p>{{ $order->completed_at?->setTimezone($timezone)->format('d-m-Y, H:i') }} · Total {{ $money($order->grand_total) }}@if (\App\Support\Money::isPositive($order->refunded_total)) · sudah diretur {{ $money($order->refunded_total) }}@endif</p>
            </div>
            <a href="{{ route('cashier.refunds.search') }}" class="site-btn site-btn-outline">@svg('heroicon-o-magnifying-glass', ['aria-hidden' => 'true']) Cari lain</a>
        </header>

        <div class="pos-detail">
            <section class="pos-card">
                <div class="pos-card-head"><h2>Item diretur</h2></div>
                @if ($order->isFullyRefunded())
                    <div class="pos-empty">
                        <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-check-badge')</span>
                        <strong>Sudah diretur semua</strong>
                        <p>Semua item transaksi ini sudah diretur.</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('cashier.refunds.store', $order->id) }}" novalidate
                        data-confirm-title="Catat retur?" data-confirm-ok="Catat retur" data-confirm-icon="warning" data-confirm-tone="danger"
                        data-confirm-text="Nilai retur dihitung ulang oleh sistem dan tidak bisa dibatalkan.">
                        @csrf
                        <input type="hidden" name="id" value="{{ $refundId }}">
                        <div class="pos-table-wrap">
                            <table class="pos-table">
                                <thead><tr><th>Item</th><th class="num">Terjual</th><th class="num">Retur</th></tr></thead>
                                <tbody>
                                    @foreach ($order->items as $i => $item)
                                        @php($left = $item->qty - $item->refunded_qty)
                                        <tr>
                                            <td class="pos-receipt-name">
                                                {{ $item->product_name }}
                                                @if ($item->options->isNotEmpty())<small>{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif
                                                @if ($item->refunded_qty > 0)<small>{{ $item->refunded_qty }} sudah diretur</small>@endif
                                            </td>
                                            <td class="num">{{ $item->qty }}</td>
                                            <td class="num">
                                                <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item->id }}">
                                                {{-- Stepper − n + seperti keranjang Transaksi (data-stepper, JS di layout) --}}
                                                <span class="pos-qty" data-stepper>
                                                    <button type="button" data-step="-1" aria-label="Kurangi {{ $item->product_name }}" @disabled($left === 0)>@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                                                    <input type="number" name="items[{{ $i }}][qty]" min="0" max="{{ $left }}" step="1" inputmode="numeric"
                                                        value="{{ old("items.$i.qty", 0) }}" aria-label="Qty retur {{ $item->product_name }}" @disabled($left === 0)>
                                                    <button type="button" data-step="1" aria-label="Tambah {{ $item->product_name }}" @disabled($left === 0)>@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="pos-card-body pos-form">
                            @error('items') <p class="pos-field-error">{{ $message }}</p> @enderror
                            <label class="pos-field" for="reason"><span>Alasan<span class="site-required" aria-hidden="true">*</span></span>
                                <input id="reason" name="reason" type="text" maxlength="255" value="{{ old('reason') }}" placeholder="mis. produk rusak" required>
                                @error('reason') <small class="pos-field-error">{{ $message }}</small> @enderror
                            </label>
                            <div class="pos-field">
                                <span>Cara pengembalian</span>
                                <div class="cashier-tabs" role="radiogroup" aria-label="Cara pengembalian">
                                    @foreach (\App\Enums\RefundMethod::cases() as $method)
                                        <label><input type="radio" name="method" value="{{ $method->value }}" @checked(old('method', 'cash') === $method->value)><span>{{ $method->getLabel() }}</span></label>
                                    @endforeach
                                </div>
                            </div>
                            <label class="pos-check">
                                <input type="hidden" name="restock" value="0">
                                <input type="checkbox" name="restock" value="1" @checked(old('restock', '1') === '1')>
                                Kembalikan barang ke stok
                                <small>Hapus centang bila barang rusak</small>
                            </label>
                            @include('cashier.partials.approver', ['approvers' => $approvers, 'id' => 'refund'])
                            <button type="submit" class="site-btn site-btn-primary">@svg('heroicon-o-arrow-uturn-left', ['aria-hidden' => 'true']) Catat retur</button>
                        </div>
                    </form>
                @endif
            </section>

            <section class="pos-card">
                <div class="pos-card-head"><h2>Riwayat retur</h2></div>
                @if ($order->refunds->isEmpty())
                    <div class="pos-empty">
                        <span class="pos-empty-icon" aria-hidden="true">@svg('heroicon-o-arrow-uturn-left')</span>
                        <strong>Belum ada retur</strong>
                        <p>Belum ada retur untuk transaksi ini.</p>
                    </div>
                @else
                    <div class="pos-table-wrap">
                        <table class="pos-table">
                            <thead><tr><th>Waktu</th><th>Item</th><th class="num">Dikembalikan</th></tr></thead>
                            <tbody>
                                @foreach ($order->refunds as $refund)
                                    <tr>
                                        <td class="pos-receipt-name">{{ $refund->created_at->setTimezone($timezone)->format('d-m-Y, H:i') }}<small>{{ $refund->user?->name }} · {{ $refund->method->getLabel() }}</small></td>
                                        <td class="pos-receipt-name">{{ $refund->items->map(fn ($i) => $i->qty.'× '.$i->product_name)->implode(', ') }}<small class="is-note">“{{ $refund->reason }}”</small></td>
                                        <td class="num is-strong">{{ $money($refund->payout_amount) }}@if ($refund->points_returned > 0)<small class="pos-cell-sub">+{{ $refund->points_returned }} poin</small>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-cashier.layout>
