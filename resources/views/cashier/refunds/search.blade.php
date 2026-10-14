{{-- /cashier/refunds: cari transaksi untuk diretur (ADR 0018). --}}
<x-cashier.layout title="Retur" active="orders">
    <section class="cashier-card cashier-narrow">
        <h1>Retur transaksi</h1>
        <p class="cashier-muted">Masukkan nomor transaksi di struk. Retur bisa sampai {{ config('pos.refund.max_days') }} hari setelah transaksi.</p>
        @include('cashier.refunds.partials.search-form')
    </section>
</x-cashier.layout>
