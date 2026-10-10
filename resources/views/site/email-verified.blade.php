{{-- Hasil klik link verifikasi email owner (ADR 0009) atau karyawan (SPEC Q46). --}}
<x-site.layout title="Email terverifikasi — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <a href="{{ route('landing') }}" class="site-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>
            <div class="site-auth-check" aria-hidden="true"><x-site.icon name="check" /></div>
            <h1>Email terverifikasi</h1>
            @if ($isOwner)
                <p class="site-auth-sub">Terima kasih, {{ $name }}. Kasir sekarang bisa bertransaksi.</p>
            @else
                <p class="site-auth-sub">Terima kasih, {{ $name }}. Email Anda sudah terverifikasi.</p>
            @endif
            @if ($continueUrl !== null)
                <a href="{{ $continueUrl }}" class="site-btn site-btn-primary site-btn-lg site-btn-block">Lanjut ke dashboard</a>
            @endif
        </div>
    </main>
</x-site.layout>
