{{-- /cashier/shifts/{id}: rekap satu shift (Z-report setelah ditutup). Gaya Q131. --}}
<x-cashier.layout title="Rekap shift" active="shift">
    <div class="pos-page">
        <header class="pos-page-head">
            <div>
                <a href="{{ route('cashier.shift') }}" class="pos-back">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Shift</a>
                <h1>Rekap shift</h1>
                <p>{{ $recap['type'] === 'z_report' ? 'Shift sudah ditutup. Simpan atau cetak laporan ini untuk serah terima.' : 'Laporan sementara shift yang masih berjalan.' }}</p>
            </div>
            @if ($recap['type'] === 'z_report')
                <a href="{{ route('cashier.shift') }}" class="site-btn site-btn-primary">@svg('heroicon-o-play', ['aria-hidden' => 'true']) Buka shift baru</a>
            @endif
        </header>

        <div class="pos-narrow is-wide">
            @include('cashier.partials.recap', ['recap' => $recap, 'timezone' => $timezone])
        </div>
    </div>
</x-cashier.layout>
