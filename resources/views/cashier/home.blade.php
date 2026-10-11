{{-- /cashier: layar transaksi (W4). --}}
<x-cashier.layout title="Transaksi" active="pos">
    <section class="cashier-card cashier-narrow">
        <h1>{{ $outlet->name }}</h1>
        <p class="cashier-muted">Shift dibuka {{ $shift->openedBy?->name }} · {{ $device->name }}</p>
    </section>
</x-cashier.layout>
