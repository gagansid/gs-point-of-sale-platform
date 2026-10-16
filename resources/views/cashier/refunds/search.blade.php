{{-- /cashier/refunds: cari transaksi untuk diretur (ADR 0018). --}}
<x-cashier.layout title="Retur" active="orders">
    <section class="cashier-card cashier-narrow">
        <h1>Retur transaksi</h1>
        <p class="cashier-muted">Masukkan nomor transaksi di struk. {{ $maxDays > 0 ? 'Retur bisa sampai '.$maxDays.' hari setelah transaksi.' : 'Retur tidak diizinkan di outlet ini (Profil outlet → Laci kasir).' }}</p>
        @include('cashier.refunds.partials.search-form')
    </section>
</x-cashier.layout>
