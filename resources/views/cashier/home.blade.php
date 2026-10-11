{{-- /cashier: beranda kasir web. Shift (W3) & layar transaksi (W4) menyusul. --}}
<x-site.layout title="Kasir web — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <span class="site-auth-logo"><x-site.logo /></span>
            <h1>{{ $outlet->name }}</h1>
            <p class="site-auth-sub">{{ $user->name }} · {{ $device->name }}</p>

            <div class="site-alert site-alert-success" role="status">Kasir web siap. Buka shift & transaksi segera tersedia.</div>

            <a href="{{ route('cashier.outlet') }}" class="site-btn site-btn-outline site-btn-block">Ganti outlet</a>

            <form method="POST" action="{{ route('cashier.logout') }}" class="site-auth-foot">
                @csrf
                <button type="submit" class="site-link-button">Keluar</button>
            </form>
        </div>
    </main>
</x-site.layout>
