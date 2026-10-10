{{-- gspos.id/forgot-password (SPEC Q34). --}}
<x-site.layout title="Lupa kata sandi — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <a href="{{ route('landing') }}" class="site-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>
            <h1>Lupa kata sandi</h1>
            <p class="site-auth-sub">Masukkan email akun owner/manager. Kami kirim link untuk membuat kata sandi baru.</p>

            @if (session('status'))
                <div class="site-alert site-alert-success" role="status">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="site-form" novalidate>
                @csrf
                <x-site.field name="email" label="Alamat email" type="email" required autocomplete="email" autofocus />
                <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Kirim link</button>
            </form>

            <p class="site-auth-foot">Kasir? Minta owner mengganti kata sandi di menu Karyawan.<br><a href="{{ $loginUrl }}">Kembali ke halaman masuk</a></p>
        </div>
    </main>
</x-site.layout>
