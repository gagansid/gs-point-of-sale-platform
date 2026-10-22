{{-- /cashier/orders/{id}: detail order (gaya "Pembayaran berhasil" sebagai halaman), struk, retur, void, pecah/gabung. Q131. --}}
@php
    $money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v);
    $positive = fn (?string $v): bool => $v !== null && \App\Support\Money::isPositive($v);
    $status = $order->status;
    $canVoid = in_array($status, [\App\Enums\OrderStatus::Open, \App\Enums\OrderStatus::AwaitingPayment, \App\Enums\OrderStatus::Completed], true);
    $canRefund = $status === \App\Enums\OrderStatus::Completed && ! $order->isFullyRefunded();
    $isOpen = $status === \App\Enums\OrderStatus::Open;
    $tendered = \App\Support\Money::add('0.00', ...$order->payments->map(fn ($p) => (string) $p->tendered)->all());
    $markIcon = match ($status) {
        \App\Enums\OrderStatus::Completed => 'heroicon-o-check',
        \App\Enums\OrderStatus::Voided => 'heroicon-o-x-mark',
        default => 'heroicon-o-clock',
    };
@endphp
<x-cashier.layout title="Transaksi {{ $order->order_number }}" active="orders">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <a href="{{ route('cashier.orders') }}" class="pos-back">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Riwayat</a>
                <h1>Detail transaksi</h1>
            </div>
            <div class="pos-head-actions">
                @can('reprint', $order)
                    <a href="{{ route('cashier.orders.receipt', ['order' => $order->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="site-btn site-btn-outline">@svg('heroicon-o-printer', ['aria-hidden' => 'true']) Cetak struk</a>
                @endcan
                {{-- Retur sebagian (ADR 0018) --}}
                @if ($canRefund)
                    <a href="{{ route('cashier.refunds.create', $order->id) }}" class="site-btn site-btn-outline">@svg('heroicon-o-arrow-uturn-left', ['aria-hidden' => 'true']) Retur</a>
                @endif
            </div>
        </header>

        <div @class(['pos-detail', 'is-single' => ! $canVoid && ! $isOpen])>
            {{-- Ringkasan seperti dialog "Pembayaran berhasil" --}}
            <section class="pos-card pos-receipt">
                <div class="pos-receipt-head">
                    <span @class(['pos-receipt-mark', 'is-'.$status->value]) aria-hidden="true">@svg($markIcon)</span>
                    <p class="pos-receipt-number">{{ $order->order_number }}</p>
                    <span @class(['pos-badge', 'is-'.$status->value])>{{ $status->getLabel() }}</span>
                    <p class="pos-receipt-meta">{{ $order->created_at->setTimezone($timezone)->format('d-m-Y, H:i') }} · {{ $order->user?->name }}</p>
                    <div class="pos-chips">
                        <span class="pos-chip">{{ $order->order_type->getLabel() }}</span>
                        @if ($order->table_label)<span class="pos-chip">@svg('heroicon-o-map-pin', ['aria-hidden' => 'true']) {{ $order->table_label }}</span>@endif
                        @if (filled($order->member_name))<span class="pos-chip">@svg('heroicon-o-user', ['aria-hidden' => 'true']) {{ $order->member_name }}</span>@endif
                    </div>
                </div>

                <ul class="pos-receipt-items">
                    @foreach ($order->items as $item)
                        <li>
                            <span class="pos-receipt-qty">{{ $item->qty }}×</span>
                            <span class="pos-receipt-name">
                                {{ $item->product_name }}
                                @if ($item->options->isNotEmpty())<small>{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif
                                @if ($item->notes)<small class="is-note">“{{ $item->notes }}”</small>@endif
                            </span>
                            <span class="pos-receipt-amount">{{ $money($item->line_total) }}</span>
                        </li>
                    @endforeach
                </ul>

                <dl class="pos-receipt-lines">
                    <div><dt>Subtotal</dt><dd>{{ $money($order->subtotal) }}</dd></div>
                    @if ($positive($order->discount_total))<div><dt>Diskon</dt><dd>−{{ $money($order->discount_total) }}</dd></div>@endif
                    @if ($positive($order->service_total))<div><dt>Service</dt><dd>{{ $money($order->service_total) }}</dd></div>@endif
                    @if ($positive($order->tax_total))<div><dt>Pajak{{ $order->tax_inclusive ? ' (termasuk)' : '' }}</dt><dd>{{ $money($order->tax_total) }}</dd></div>@endif
                    @if (\App\Support\Money::compare($order->rounding, '0') !== 0)<div><dt>Pembulatan</dt><dd>{{ $money($order->rounding) }}</dd></div>@endif
                    <div class="is-total"><dt>Total</dt><dd>{{ $money($order->grand_total) }}</dd></div>
                </dl>

                @if ($order->payments->isNotEmpty() || $positive($order->refunded_total))
                    <dl class="pos-receipt-lines is-payments">
                        @foreach ($order->payments as $payment)
                            <div><dt>{{ $payment->method?->name }}</dt><dd>{{ $money($payment->amount) }}</dd></div>
                        @endforeach
                        @if ($positive($tendered))<div class="is-muted"><dt>Uang diterima</dt><dd>{{ $money($tendered) }}</dd></div>@endif
                        @if ($positive($order->change_total))<div class="is-muted"><dt>Kembalian</dt><dd>{{ $money($order->change_total) }}</dd></div>@endif
                        @if ($positive($order->refunded_total))<div class="is-danger"><dt>Diretur</dt><dd>−{{ $money($order->refunded_total) }}</dd></div>@endif
                    </dl>
                @endif

                @if ($order->void_reason)
                    <div class="pos-callout is-danger" role="note">
                        @svg('heroicon-o-x-circle', ['aria-hidden' => 'true'])
                        <p><strong>Alasan void:</strong> {{ $order->void_reason }}</p>
                    </div>
                @endif
            </section>

            @if ($canVoid || $isOpen)
                <div class="pos-detail-side">
                    {{-- Pecah & gabung open bill (ADR 0019): belum dibayar, tanpa voucher/stempel; diskon manual ikut dibagi --}}
                    @if ($isOpen)
                        <section class="pos-card">
                            <div class="pos-card-head"><h2>Pecah bill</h2></div>
                            <form method="POST" action="{{ route('cashier.orders.split', $order->id) }}" class="pos-card-body pos-form" novalidate>
                                @csrf
                                <p class="pos-hint">Pindahkan item ke bill baru, mis. tamu yang bayar sendiri. Harga kedua bill dihitung ulang.</p>
                                <input type="hidden" name="new_order_id" value="{{ $newOrderId }}">
                                <ul class="pos-split-items">
                                    @foreach ($order->items as $i => $item)
                                        <li>
                                            <span class="pos-receipt-name">
                                                {{ $item->product_name }}
                                                <small>{{ $item->qty }} di bill ini{{ $item->options->isNotEmpty() ? ' · '.$item->options->pluck('option_name')->implode(', ') : '' }}</small>
                                            </span>
                                            <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item->id }}">
                                            {{-- Stepper − n + seperti keranjang Transaksi (data-stepper, JS di layout) --}}
                                            <span class="pos-qty" data-stepper>
                                                <button type="button" data-step="-1" aria-label="Kurangi {{ $item->product_name }}">@svg('heroicon-o-minus', ['aria-hidden' => 'true'])</button>
                                                <input type="number" name="items[{{ $i }}][qty]" min="0" max="{{ $item->qty }}" step="1" inputmode="numeric" value="0" aria-label="Qty pindah {{ $item->product_name }}">
                                                <button type="button" data-step="1" aria-label="Tambah {{ $item->product_name }}">@svg('heroicon-o-plus', ['aria-hidden' => 'true'])</button>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                <label class="pos-field"><span>Meja bill baru</span>
                                    <input name="table_label" type="text" maxlength="50" value="{{ old('table_label', $order->table_label) }}">
                                </label>
                                <button type="submit" class="site-btn site-btn-outline">@svg('heroicon-o-scissors', ['aria-hidden' => 'true']) Pecah ke bill baru</button>
                            </form>
                        </section>

                        <section class="pos-card">
                            <div class="pos-card-head"><h2>Gabung bill</h2></div>
                            @if ($otherBills->isEmpty())
                                <p class="pos-card-body pos-hint">Tidak ada open bill lain yang bisa digabung.</p>
                            @else
                                <form method="POST" action="{{ route('cashier.orders.merge', $order->id) }}" class="pos-card-body pos-form" novalidate
                                    data-confirm-title="Gabung bill?" data-confirm-ok="Gabung" data-confirm-icon="merge"
                                    data-confirm-text="Semua item bill yang dipilih pindah ke {{ $order->order_number }}.">
                                    @csrf
                                    <label class="pos-field"><span>Gabungkan dari</span>
                                        <select name="source_order_id" class="pos-select" required>
                                            @foreach ($otherBills as $bill)
                                                <option value="{{ $bill->id }}">{{ $bill->order_number }}@if ($bill->table_label) · {{ $bill->table_label }}@endif · {{ $money($bill->grand_total) }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button type="submit" class="site-btn site-btn-outline">@svg('heroicon-o-square-2-stack', ['aria-hidden' => 'true']) Gabung ke bill ini</button>
                                </form>
                            @endif
                        </section>
                    @endif

                    @if ($canVoid)
                        <section class="pos-card">
                            <div class="pos-card-head"><h2>Void transaksi</h2></div>
                            <form method="POST" action="{{ route('cashier.orders.void', $order->id) }}" class="pos-card-body pos-form" novalidate
                                data-confirm-title="Void {{ $order->order_number }}?" data-confirm-ok="Void" data-confirm-icon="warning" data-confirm-tone="danger"
                                data-confirm-text="Stok dikembalikan dan omzet dikeluarkan dari laporan. Tindakan ini tidak bisa dibatalkan.">
                                @csrf
                                <p class="pos-hint">Hanya selama shift transaksi masih terbuka.</p>
                                <label class="pos-field"><span>Alasan<span class="site-required" aria-hidden="true">*</span></span>
                                    <input name="reason" type="text" maxlength="255" value="{{ old('reason') }}" placeholder="mis. salah input" required>
                                    @error('reason') <small class="pos-field-error">{{ $message }}</small> @enderror
                                </label>

                                @unless ($canVoidSelf)
                                    @include('cashier.partials.approver', ['approvers' => $approvers, 'id' => 'void'])
                                @endunless

                                <button type="submit" class="site-btn pos-btn-danger">@svg('heroicon-o-x-circle', ['aria-hidden' => 'true']) Void transaksi</button>
                            </form>
                        </section>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-cashier.layout>
