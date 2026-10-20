{{-- gspos.id/forgot-password (SPEC Q34). --}}
<x-auth.layout title="Lupa kata sandi" app="Dashboard" :home="route('landing')"
    heading="Akses akun kembali dalam semenit"
    text="Kami kirim link aman ke email Anda untuk membuat kata sandi baru."
    :points="[['mail', 'Link dikirim ke email terdaftar'], ['clock', 'Berlaku 60 menit, sekali pakai'], ['shield', 'Sesi lama di perangkat lain dikeluarkan']]">
    <h1 class="cashier-auth-title">Lupa kata sandi</h1>
    <p class="cashier-auth-sub">Masukkan email akun owner/manager. Kami kirim link untuk membuat kata sandi baru.</p>

    @if (session('status'))
        <div class="cashier-auth-alert is-success" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="cashier-auth-form" novalidate>
        @csrf
        <x-auth.field name="email" label="Alamat email" type="email" icon="mail" required autofocus autocomplete="email" placeholder="nama@bisnis.com" />

        <x-auth.turnstile :audience="\App\Services\Security\Turnstile::OWNER" />

        <button type="submit" class="cashier-auth-btn">Kirim link</button>
    </form>

    <p class="cashier-auth-foot">
        Kasir? Minta owner mengganti kata sandi di menu Karyawan.<br>
        <a href="{{ $loginUrl }}" class="auth-link auth-back"><x-site.icon name="arrow-left" /> Kembali ke halaman masuk</a>
    </p>
</x-auth.layout>
