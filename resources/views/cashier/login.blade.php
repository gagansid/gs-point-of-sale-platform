{{-- /cashier/login: kasir web (Q54). Tab email (owner/manager) atau kode bisnis + username (supervisor/kasir). --}}
<x-site.layout title="Kasir web — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <span class="site-auth-logo"><x-site.logo /></span>
            <h1>Kasir web</h1>
            <p class="site-auth-sub">Cadangan bila tablet kasir tidak bisa dipakai</p>

            @if (session('status'))
                <div class="site-alert site-alert-warning" role="status">{{ session('status') }}</div>
            @endif

            <nav class="site-segment" aria-label="Cara masuk">
                <a href="{{ route('cashier.login', ['mode' => 'username']) }}" @class(['is-active' => $mode === 'username']) @if ($mode === 'username') aria-current="page" @endif>Username</a>
                <a href="{{ route('cashier.login', ['mode' => 'email']) }}" @class(['is-active' => $mode === 'email']) @if ($mode === 'email') aria-current="page" @endif>Email</a>
            </nav>

            <form method="POST" action="{{ route('cashier.login.store') }}" class="site-form" novalidate>
                @csrf
                <input type="hidden" name="mode" value="{{ $mode }}">

                @if ($mode === 'username')
                    <div class="site-field">
                        <label for="business">Kode bisnis<span class="site-required" aria-hidden="true">*</span></label>
                        <input id="business" name="business" type="text" value="{{ $business }}" required autocomplete="organization"
                            autocapitalize="none" spellcheck="false" @if (blank($business)) autofocus @endif
                            @error('business') aria-invalid="true" aria-describedby="business-error" @enderror>
                        @error('business') <p id="business-error" class="site-error">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="site-field">
                    <label for="login">{{ $mode === 'username' ? 'Username' : 'Alamat email' }}<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="login" name="login" type="{{ $mode === 'username' ? 'text' : 'email' }}" value="{{ old('login') }}" required
                        autocomplete="username" autocapitalize="none" spellcheck="false" @if ($mode === 'email' || filled($business)) autofocus @endif
                        @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>
                    @error('login') <p id="login-error" class="site-error">{{ $message }}</p> @enderror
                </div>

                <div class="site-field">
                    <label for="password">Kata sandi<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="password" name="password" type="password" required autocomplete="current-password">
                    @error('password') <p class="site-error">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Masuk</button>
            </form>

            <p class="site-auth-foot">Akses kasir web diatur owner di menu Karyawan.</p>
        </div>
    </main>
</x-site.layout>
