{{-- gspos.id/login: login owner/manager → app.gspos.id lewat tiket sekali pakai (ADR 0008). --}}
<x-site.layout title="Masuk — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <a href="{{ route('landing') }}" class="site-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>
            <h1>Masuk ke akun Anda</h1>
            <p class="site-auth-sub">Dashboard owner & manager</p>

            @if (session('status'))
                <div class="site-alert site-alert-success" role="status">{{ session('status') }}</div>
            @endif

            @if ($expired)
                <div class="site-alert site-alert-warning" role="alert">Sesi login kedaluwarsa. Silakan masuk lagi.</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}" class="site-form" novalidate>
                @csrf
                @if ($next)
                    <input type="hidden" name="next" value="{{ $next }}">
                @endif

                <div class="site-field">
                    <label for="email">Alamat email<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                    @error('email') <p id="email-error" class="site-error">{{ $message }}</p> @enderror
                </div>

                <div class="site-field">
                    <label for="password">Kata sandi<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    @error('password') <p class="site-error">{{ $message }}</p> @enderror
                </div>

                <div class="site-login-row">
                    <label class="site-check">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya
                    </label>
                    <a href="{{ route('password.request') }}" class="site-link-small">Lupa kata sandi?</a>
                </div>

                <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Masuk</button>
            </form>

            @if (\App\Support\Edition::isSaas())
                <p class="site-auth-foot">Belum punya akun? <a href="{{ route('landing') }}#contact">Hubungi sales</a></p>
            @endif
        </div>
    </main>
</x-site.layout>
