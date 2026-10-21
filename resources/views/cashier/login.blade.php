{{-- /cashier/login: kasir web (Q54). Tab username (kode bisnis + username) atau email; tab dipilih di form, bukan di URL. --}}
<x-cashier.auth title="Masuk kasir web">
    <h1 class="cashier-auth-title">Masuk ke kasir</h1>
    <p class="cashier-auth-sub">Catat pesanan dan terima pembayaran langsung dari browser.</p>

    @if (session('status'))
        <div class="cashier-auth-alert" role="status">{{ session('status') }}</div>
    @endif

    <form method="POST" action="{{ route('cashier.login.store') }}" class="cashier-auth-form" novalidate>
        @csrf

        <div class="cashier-tabs" role="radiogroup" aria-label="Cara masuk">
            <label><input type="radio" name="mode" value="username" @checked($mode === 'username')><span>Username</span></label>
            <label><input type="radio" name="mode" value="email" @checked($mode === 'email')><span>Email</span></label>
        </div>

        <x-auth.field name="business" label="Kode bisnis" icon="store" data-mode="username" :value="$business"
            autocomplete="organization" autocapitalize="none" spellcheck="false" placeholder="mis. kopi-senja"
            hint="Kode dari owner, sama dengan di aplikasi kasir"
            :autofocus="$mode === 'username' && blank($business)" />

        <div class="cashier-field">
            <label for="login"><span data-mode="username">Username</span><span data-mode="email">Alamat email</span></label>
            <div class="auth-input has-icon">
                {{-- Ikon & placeholder mengikuti tab (CSS data-mode + skrip kecil di bawah) --}}
                <x-site.icon name="user" class="auth-input-icon" data-mode="username" />
                <x-site.icon name="mail" class="auth-input-icon" data-mode="email" />
                <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" autocapitalize="none"
                    spellcheck="false" placeholder="{{ $mode === 'email' ? 'nama@bisnis.com' : 'mis. sari' }}"
                    data-placeholder-username="mis. sari" data-placeholder-email="nama@bisnis.com"
                    @if ($mode === 'email' || filled($business)) autofocus @endif
                    @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>
            </div>
            @error('login') <p id="login-error" class="cashier-field-error">{{ $message }}</p> @enderror
        </div>

        <x-auth.field name="password" label="Kata sandi" type="password" icon="lock" autocomplete="current-password" placeholder="Kata sandi" />

        <x-auth.turnstile :audience="\App\Services\Security\Turnstile::CASHIER" />

        <button type="submit" class="cashier-auth-btn">Masuk</button>
    </form>

    <p class="cashier-auth-foot">Belum punya akses? Minta owner mengaktifkan kasir web untuk akun Anda.</p>

    <script>
        // Placeholder & keyboard field login mengikuti tab Username / Email
        document.querySelectorAll('input[name="mode"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var login = document.getElementById('login');
                login.placeholder = login.dataset['placeholder' + (radio.value === 'email' ? 'Email' : 'Username')];
                login.inputMode = radio.value === 'email' ? 'email' : 'text';
            });
        });
    </script>
</x-cashier.auth>
