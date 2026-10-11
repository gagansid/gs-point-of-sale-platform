{{-- /cashier/orders/{id}: detail order, struk, dan void. --}}
@php($money = fn (?string $v): string => $v === null ? '–' : \App\Support\Money::format($v))
<x-cashier.layout title="Order {{ $order->order_number }}" active="orders">
    <div class="cashier-grid-2">
        <section class="cashier-card">
            <div class="cashier-card-head">
                <h1>{{ $order->order_number }}@if ($order->table_label) · {{ $order->table_label }}@endif</h1>
                <span @class(['cashier-badge', 'is-'.$order->status->value])>{{ $order->status->getLabel() }}</span>
            </div>
            <p class="cashier-muted">{{ $order->created_at->setTimezone($timezone)->format('j M Y H.i') }} · {{ $order->user?->name }} · {{ $order->order_type->getLabel() }}</p>

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
                @foreach ($order->payments as $payment)
                    <dt>· {{ $payment->method?->name }}</dt><dd>{{ $money($payment->amount) }}</dd>
                @endforeach
                @if ($order->void_reason)
                    <dt>Alasan void</dt><dd>{{ $order->void_reason }}</dd>
                @endif
            </dl>

            @can('reprint', $order)
                <p style="margin-top: 16px">
                    <a href="{{ route('cashier.orders.receipt', ['order' => $order->id, 'print' => 1]) }}" target="_blank" rel="noopener" class="site-btn site-btn-outline">Cetak struk</a>
                </p>
            @endcan
        </section>

        @if ($order->status !== \App\Enums\OrderStatus::Voided)
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
    </div>
</x-cashier.layout>
