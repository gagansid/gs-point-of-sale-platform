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

        <div class="cashier-field" data-mode="username">
            <label for="business">Kode bisnis</label>
            <input id="business" name="business" type="text" value="{{ $business }}" autocomplete="organization" autocapitalize="none"
                spellcheck="false" placeholder="mis. kopi-senja" @if ($mode === 'username' && blank($business)) autofocus @endif
                @error('business') aria-invalid="true" aria-describedby="business-error" @enderror>
            @error('business') <p id="business-error" class="cashier-field-error">{{ $message }}</p> @enderror
        </div>

        <div class="cashier-field">
            <label for="login"><span data-mode="username">Username</span><span data-mode="email">Alamat email</span></label>
            <input id="login" name="login" type="text" value="{{ old('login') }}" autocomplete="username" autocapitalize="none"
                spellcheck="false" @if ($mode === 'email' || filled($business)) autofocus @endif
                @error('login') aria-invalid="true" aria-describedby="login-error" @enderror>
            @error('login') <p id="login-error" class="cashier-field-error">{{ $message }}</p> @enderror
        </div>

        <div class="cashier-field">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password"
                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password') <p id="password-error" class="cashier-field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="cashier-auth-btn">Masuk</button>
    </form>

    <p class="cashier-auth-foot">Belum bisa masuk? Minta owner mengaktifkan akses kasir web untuk akun Anda.</p>
</x-cashier.auth>
