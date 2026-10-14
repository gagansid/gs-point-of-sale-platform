{{-- /cashier/orders/{id}: detail order, struk, dan void. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Order {{ $order->order_number }}" active="orders">
    <div class="cashier-grid-2">
        <section class="cashier-card">
            <div class="cashier-card-head">
                <h1>{{ $order->order_number }}@if ($order->table_label) · {{ $order->table_label }}@endif</h1>
                <span @class(['cashier-badge', 'is-'.$order->status->value])>{{ $order->status->getLabel() }}</span>
            </div>
            <p class="cashier-muted">{{ $order->created_at->setTimezone($timezone)->format('d-m-Y, H:i') }} · {{ $order->user?->name }} · {{ $order->order_type->getLabel() }}@if (filled($order->member_name)) · Member {{ $order->member_name }}@endif</p>

            <table class="cashier-table">
                <tbody>
                    @foreach ($order->items as $item)
                        <tr>
                            <td>
                                {{ $item->qty }}× {{ $item->product_name }}
                                @if ($item->options->isNotEmpty())<br><small class="cashier-muted">{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif
                                @if ($item->notes)<br><small class="cashier-muted">“{{ $item->notes }}”</small>@endif
                            </td>
                            <td class="num">{{ $money($item->line_total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <dl class="cashier-dl" style="margin-top: 12px">
                <dt>Total</dt><dd><strong>{{ $money($order->grand_total) }}</strong></dd>
                <dt>Dibayar</dt><dd>{{ $money($order->paid_total) }}</dd>
                @if (\App\Support\Money::isPositive($order->refunded_total))
                    <dt>Diretur</dt><dd class="cashier-negative">−{{ $money($order->refunded_total) }}</dd>
                @endif
                @foreach ($order->payments as $payment)
                    <dt>· {{ $payment->method?->name }}</dt><dd>{{ $money($payment->amount) }}</dd>
                @endforeach
                @if ($order->void_reason)
                    <dt>Alasan void</dt><dd>{{ $order->void_reason }}</dd>
                @endif
            </dl>

            <p style="margin-top: 16px">
                @can('reprint', $order)
                    <a href="{{ route('cashier.orders.receipt', ['order' => $order->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="site-btn site-btn-outline">Cetak struk</a>
                @endcan
                {{-- Retur sebagian (ADR 0018) --}}
                @if ($order->status === \App\Enums\OrderStatus::Completed && ! $order->isFullyRefunded())
                    <a href="{{ route('cashier.refunds.create', $order->id) }}" class="site-btn site-btn-outline">Retur</a>
                @endif
            </p>
        </section>

        @if (in_array($order->status, [\App\Enums\OrderStatus::Open, \App\Enums\OrderStatus::AwaitingPayment, \App\Enums\OrderStatus::Completed], true))
            <section class="cashier-card">
                <h2>Void order</h2>
                <p class="cashier-muted">Stok dikembalikan dan omzet dikeluarkan dari laporan. Hanya selama shift order masih terbuka.</p>
                <form method="POST" action="{{ route('cashier.orders.void', $order->id) }}" class="site-form" novalidate>
                    @csrf
                    <div class="site-field">
                        <label for="reason">Alasan<span class="site-required" aria-hidden="true">*</span></label>
                        <input id="reason" name="reason" type="text" maxlength="255" value="{{ old('reason') }}" required>
                        @error('reason') <p class="site-error">{{ $message }}</p> @enderror
                    </div>

                    @unless ($canVoidSelf)
                        <div class="site-field">
                            <label for="approver_user_id">Approver<span class="site-required" aria-hidden="true">*</span></label>
                            <select id="approver_user_id" name="approver_user_id" class="cashier-select">
                                <option value="">Pilih approver…</option>
                                @foreach ($approvers as $approver)
                                    <option value="{{ $approver->id }}" @selected(old('approver_user_id') === $approver->id)>{{ $approver->name }} ({{ $approver->role->getLabel() }})</option>
                                @endforeach
                            </select>
                            @error('approver_user_id') <p class="site-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="site-field">
                            <label for="approver_pin">PIN approver<span class="site-required" aria-hidden="true">*</span></label>
                            <input id="approver_pin" name="approver_pin" type="password" inputmode="numeric" maxlength="6" autocomplete="off">
                            @error('approver_pin') <p class="site-error">{{ $message }}</p> @enderror
                        </div>
                    @endunless

                    <button type="submit" class="site-btn site-btn-primary site-btn-block" data-confirm-step="Klik lagi untuk void">Void</button>
                </form>
            </section>
        @endif

        {{-- Pecah & gabung open bill (ADR 0019): belum dibayar, tanpa diskon/voucher/stempel --}}
        @if ($order->status === \App\Enums\OrderStatus::Open)
            <section class="cashier-card">
                <h2>Pecah bill</h2>
                <p class="cashier-muted">Pindahkan item ke bill baru, mis. tamu yang bayar sendiri. Harga kedua bill dihitung ulang.</p>
                <form method="POST" action="{{ route('cashier.orders.split', $order->id) }}" class="site-form" novalidate>
                    @csrf
                    <input type="hidden" name="new_order_id" value="{{ $newOrderId }}">
                    <table class="cashier-table">
                        <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Pindah</th></tr></thead>
                        <tbody>
                            @foreach ($order->items as $i => $item)
                                <tr>
                                    <td>{{ $item->product_name }}@if ($item->options->isNotEmpty())<br><small class="cashier-muted">{{ $item->options->pluck('option_name')->implode(', ') }}</small>@endif</td>
                                    <td class="num">{{ $item->qty }}</td>
                                    <td class="num">
                                        <input type="hidden" name="items[{{ $i }}][order_item_id]" value="{{ $item->id }}">
                                        <input type="number" name="items[{{ $i }}][qty]" min="0" max="{{ $item->qty }}" step="1" inputmode="numeric" value="0" class="cashier-qty" aria-label="Qty pindah {{ $item->product_name }}">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="site-field">
                        <label for="split-table">Meja bill baru</label>
                        <input id="split-table" name="table_label" type="text" maxlength="50" value="{{ old('table_label', $order->table_label) }}">
                    </div>
                    <button type="submit" class="site-btn site-btn-outline site-btn-block">Pecah ke bill baru</button>
                </form>
            </section>

            <section class="cashier-card">
                <h2>Gabung bill</h2>
                @if ($otherBills->isEmpty())
                    <p class="cashier-muted">Tidak ada open bill lain yang bisa digabung.</p>
                @else
                    <p class="cashier-muted">Semua item bill yang dipilih pindah ke bill ini.</p>
                    <form method="POST" action="{{ route('cashier.orders.merge', $order->id) }}" class="site-form" novalidate>
                        @csrf
                        <div class="site-field">
                            <label for="source_order_id">Gabungkan dari</label>
                            <select id="source_order_id" name="source_order_id" class="cashier-select" required>
                                @foreach ($otherBills as $bill)
                                    <option value="{{ $bill->id }}">{{ $bill->order_number }}@if ($bill->table_label) · {{ $bill->table_label }}@endif · {{ $money($bill->grand_total) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="site-btn site-btn-outline site-btn-block" data-confirm-step="Klik lagi untuk menggabung">Gabung ke bill ini</button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</x-cashier.layout>
