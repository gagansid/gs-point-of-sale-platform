{{-- /cashier/orders: transaksi shift terbuka di browser ini. --}}
<x-cashier.layout title="Riwayat" active="orders">
    <section class="cashier-card">
        <div class="cashier-card-head">
            <h1>Riwayat shift ini</h1>
            <a href="{{ route('cashier.refunds.search') }}" class="site-btn site-btn-outline">Retur transaksi</a>
        </div>

        @if ($shift === null)
            <p class="cashier-muted">Belum ada shift terbuka. <a href="{{ route('cashier.shift') }}">Buka shift</a></p>
        @elseif ($orders->isEmpty())
            <p class="cashier-muted">Belum ada transaksi di shift ini.</p>
        @else
            <table class="cashier-table">
                <thead><tr><th>No.</th><th>Waktu</th><th>Kasir</th><th>Status</th><th class="num">Total</th><th></th></tr></thead>
                <tbody>
                    @foreach ($orders as $order)
                        <tr>
                            <td><a href="{{ route('cashier.orders.show', $order->id) }}">{{ $order->order_number }}</a>@if ($order->table_label) · {{ $order->table_label }}@endif</td>
                            <td>{{ $order->created_at->setTimezone($timezone)->format('H:i') }}</td>
                            <td>{{ $order->user?->name }}</td>
                            <td><span @class(['cashier-badge', 'is-'.$order->status->value])>{{ $order->status->getLabel() }}</span></td>
                            <td class="num">{{ \App\Support\Money::format($order->grand_total) }}</td>
                            <td class="num">@can('reprint', $order)<a href="{{ route('cashier.orders.receipt', ['order' => $order->id, 'print' => 1]) }}" target="_blank" rel="noopener">Struk</a>@endcan</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-cashier.layout>
