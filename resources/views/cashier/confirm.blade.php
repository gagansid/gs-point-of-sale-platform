{{-- /cashier/confirm: masuk ulang dari menu Kasir dashboard (Q55). Tab PIN hanya bila user punya PIN; tab tidak di URL. Tanpa link lupa kata sandi/PIN. --}}
<x-cashier.auth title="Masuk kasir web">
    <h1 class="cashier-auth-title">Masuk ke kasir</h1>
    <p class="cashier-auth-sub">Masuk sebagai <strong>{{ $user->name }}</strong>. Konfirmasi dulu sebelum mulai berjualan.</p>

    @error('login')
        <div class="cashier-auth-alert" role="alert">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('cashier.confirm.store') }}" class="cashier-auth-form" novalidate>
        @csrf

        @if ($hasPin)
            <div class="cashier-tabs" role="radiogroup" aria-label="Cara masuk">
                <label><input type="radio" name="method" value="pin" @checked($method === 'pin')><span>PIN</span></label>
                <label><input type="radio" name="method" value="password" @checked($method === 'password')><span>Kata sandi</span></label>
            </div>

            <div class="cashier-field" data-mode="pin">
                <label for="pin">PIN</label>
                <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="off"
                    placeholder="6 digit" @if ($method === 'pin') autofocus @endif
                    @error('pin') aria-invalid="true" aria-describedby="pin-error" @enderror>
                @error('pin') <p id="pin-error" class="cashier-field-error">{{ $message }}</p> @enderror
            </div>
        @else
            <input type="hidden" name="method" value="password">
        @endif

        <div class="cashier-field" data-mode="password">
            <label for="password">Kata sandi</label>
            <input id="password" name="password" type="password" autocomplete="current-password" @if ($method === 'password') autofocus @endif
                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
            @error('password') <p id="password-error" class="cashier-field-error">{{ $message }}</p> @enderror
        </div>

        <button type="submit" class="cashier-auth-btn">Masuk kasir</button>
    </form>

    <p class="cashier-auth-foot">PIN atau kata sandi terkunci? Hubungi atasan.</p>
</x-cashier.auth>
