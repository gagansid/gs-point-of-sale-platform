{{-- gspos.id/login: login owner/manager → app.gspos.id lewat tiket sekali pakai (ADR 0008). --}}
<x-auth.layout title="Masuk" app="Dashboard" :home="route('landing')">
    <h1 class="cashier-auth-title">Masuk ke akun Anda</h1>
    <p class="cashier-auth-sub">Dashboard owner & manager</p>

    @if (session('status'))
        <div class="cashier-auth-alert is-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($expired)
        <div class="cashier-auth-alert" role="alert">Sesi login kedaluwarsa. Silakan masuk lagi.</div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="cashier-auth-form" novalidate>
        @csrf
        @if ($next)
            <input type="hidden" name="next" value="{{ $next }}">
        @endif

        <x-auth.field name="email" label="Alamat email" type="email" icon="mail" required autofocus autocomplete="username" placeholder="nama@bisnis.com" />
        <x-auth.field name="password" label="Kata sandi" type="password" icon="lock" required autocomplete="current-password" placeholder="Kata sandi" />

        <div class="auth-row">
            <label class="auth-check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya</label>
            <a href="{{ route('password.request') }}" class="auth-link">Lupa kata sandi?</a>
        </div>

        <x-auth.turnstile :audience="\App\Services\Security\Turnstile::OWNER" />

        <button type="submit" class="cashier-auth-btn">Masuk</button>
    </form>

    @if (\App\Support\Edition::isSaas())
        <p class="cashier-auth-foot">Belum punya akun? <a href="{{ route('signup') }}" class="auth-link">Coba gratis</a> atau <a href="{{ route('landing') }}#contact" class="auth-link">hubungi sales</a></p>
    @endif
</x-auth.layout>
