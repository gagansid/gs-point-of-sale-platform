{{-- /cashier/shifts/{id}: rekap satu shift (Z-report setelah ditutup). --}}
<x-cashier.layout title="Rekap shift" active="shift">
    <div class="cashier-narrow">
        @include('cashier.partials.recap', ['recap' => $recap, 'timezone' => $timezone])
        @if ($recap['type'] === 'z_report')
            <a href="{{ route('cashier.shift') }}" class="site-btn site-btn-primary site-btn-lg site-btn-block">Buka shift baru</a>
        @endif
    </div>
</x-cashier.layout>
