{{-- /cashier/refunds: cari transaksi untuk diretur (ADR 0018). Gaya halaman kasir Q131. --}}
<x-cashier.layout title="Retur" active="orders">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <a href="{{ route('cashier.orders') }}" class="pos-back">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Riwayat</a>
                <h1>Retur transaksi</h1>
                <p>Masukkan nomor transaksi yang tertera di struk.</p>
            </div>
        </header>

        <section class="pos-card pos-narrow">
            <div class="pos-card-body pos-form">
                @include('cashier.refunds.partials.search-form')
                <div @class(['pos-callout', 'is-danger' => $maxDays <= 0]) role="note">
                    @svg($maxDays > 0 ? 'heroicon-o-information-circle' : 'heroicon-o-no-symbol', ['aria-hidden' => 'true'])
                    <p>{{ $maxDays > 0 ? 'Retur bisa sampai '.$maxDays.' hari setelah transaksi.' : 'Retur tidak diizinkan di outlet ini (Profil outlet → Laci kasir).' }}</p>
                </div>
            </div>
        </section>
    </div>
</x-cashier.layout>
