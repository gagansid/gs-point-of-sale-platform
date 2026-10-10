{{-- Pembayaran order sebagai tabel ringkas. --}}
@php
    /** @var \App\Models\Order $order */
    $order = $getRecord();
    $rp = fn (string $value): string => \App\Support\Money::format($value);
@endphp

@if ($order->payments->isEmpty())
    <p class="gs-detail-empty">Belum ada pembayaran</p>
@else
    <table class="gs-detail-table">
        <thead>
            <tr>
                <th>Metode</th>
                <th class="is-num">Nominal</th>
                <th class="is-num">Kembalian</th>
                <th class="is-end">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->payments as $payment)
                <tr>
                    <td>
                        <div class="gs-detail-name">{{ $payment->method?->name ?? $payment->category->getLabel() }}</div>
                        @if (filled($payment->reference))
                            <div class="gs-detail-sub">Ref. {{ $payment->reference }}</div>
                        @endif
                    </td>
                    <td class="is-num is-strong">{{ $rp($payment->amount) }}</td>
                    <td class="is-num">{{ $rp($payment->change) }}</td>
                    <td class="is-end">
                        <x-filament::badge :color="$payment->status->getColor()">{{ $payment->status->getLabel() }}</x-filament::badge>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
