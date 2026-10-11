{{-- /cashier/outlet: pilih outlet; browser ini menjadi perangkat kasir web di outlet itu (Q54). --}}
<x-site.layout title="Pilih outlet — Kasir web gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <span class="site-auth-logo"><x-site.logo /></span>
            <h1>Pilih outlet</h1>
            <p class="site-auth-sub">Masuk sebagai {{ $user->name }}. Browser ini dipakai sebagai kasir di outlet terpilih.</p>

            @if (session('status'))
                <div class="site-alert site-alert-warning" role="status">{{ session('status') }}</div>
            @endif
            @error('outlet_id')
                <div class="site-alert site-alert-warning" role="alert">{{ $message }}</div>
            @enderror

            @forelse ($outlets as $outlet)
                <form method="POST" action="{{ route('cashier.outlet.store') }}">
                    @csrf
                    <input type="hidden" name="outlet_id" value="{{ $outlet->id }}">
                    <button type="submit" class="site-choice">
                        <span class="site-choice-name">{{ $outlet->name }}</span>
                        <span class="site-choice-meta">{{ $outlet->code }}</span>
                    </button>
                </form>
            @empty
                <p class="site-auth-sub">Belum ada outlet aktif yang ditugaskan kepada Anda. Hubungi owner.</p>
            @endforelse

            <form method="POST" action="{{ route('cashier.logout') }}" class="site-auth-foot">
                @csrf
                <button type="submit" class="site-link-button">Keluar</button>
            </form>
        </div>
    </main>
</x-site.layout>
