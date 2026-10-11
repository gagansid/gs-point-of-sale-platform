{{-- /cashier/confirm: masuk ulang dari menu Kasir dashboard (Q55). Tab PIN hanya bila user punya PIN. Tanpa link lupa kata sandi/PIN. --}}
<x-site.layout title="Kasir web — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card">
            <span class="site-auth-logo"><x-site.logo /></span>
            <h1>Kasir web</h1>
            <p class="site-auth-sub">Masuk sebagai <strong>{{ $user->name }}</strong></p>

            @error('login')
                <div class="site-alert site-alert-warning" role="alert">{{ $message }}</div>
            @enderror

            @if ($hasPin)
                <nav class="site-segment" aria-label="Cara masuk">
                    <a href="{{ route('cashier.confirm', ['method' => 'password']) }}" @class(['is-active' => $method === 'password']) @if ($method === 'password') aria-current="page" @endif>Kata sandi</a>
                    <a href="{{ route('cashier.confirm', ['method' => 'pin']) }}" @class(['is-active' => $method === 'pin']) @if ($method === 'pin') aria-current="page" @endif>PIN</a>
                </nav>
            @endif

            <form method="POST" action="{{ route('cashier.confirm.store') }}" class="site-form" novalidate>
                @csrf
                <input type="hidden" name="method" value="{{ $method }}">

                <div class="site-field">
                    <label for="secret">{{ $method === 'pin' ? 'PIN' : 'Kata sandi' }}<span class="site-required" aria-hidden="true">*</span></label>
                    @if ($method === 'pin')
                        <input id="secret" name="secret" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" required
                            autocomplete="off" autofocus @error('secret') aria-invalid="true" aria-describedby="secret-error" @enderror>
                    @else
                        <input id="secret" name="secret" type="password" required autocomplete="current-password" autofocus
                            @error('secret') aria-invalid="true" aria-describedby="secret-error" @enderror>
                    @endif
                    @error('secret') <p id="secret-error" class="site-error">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Masuk kasir</button>
            </form>

            <p class="site-auth-foot">
                @unless ($hasPin)
                    PIN belum diatur, jadi masuk memakai kata sandi.<br>
                @endunless
                Akun terkunci? Owner dapat membukanya di dashboard, menu Karyawan.
            </p>
        </div>
    </main>
</x-site.layout>
