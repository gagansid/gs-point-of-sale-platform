{{-- gspos.id/register: daftar mandiri → trial (ADR 0009). Kerangka & field sama dengan halaman masuk (x-auth.*). --}}
<x-auth.layout title="Coba gratis" app="Dashboard" :home="route('landing')" :brand="false">
    @if (! $open)
        <h1 class="cashier-auth-title">Pendaftaran sedang ditutup</h1>
        <p class="cashier-auth-sub">{{ $closedMessage ?? 'Tim kami siap membantu menyiapkan akun Anda.' }}</p>
        <a href="{{ route('landing') }}#contact" class="cashier-auth-btn">Hubungi sales</a>
        <p class="cashier-auth-foot">Sudah punya akun? <a href="{{ $loginUrl }}" class="auth-link">Masuk</a></p>
    @else
        <h1 class="cashier-auth-title">Coba gratis {{ $trialDays }} hari</h1>
        <p class="cashier-auth-sub">Tanpa kartu kredit. Bisa langsung menyiapkan menu.</p>

        <form method="POST" action="{{ route('signup.store') }}" class="cashier-auth-form" novalidate>
            @csrf

            {{-- Honeypot: field tersembunyi, hanya diisi bot --}}
            <div class="site-hp" aria-hidden="true">
                <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="auth-grid">
            <x-auth.field name="business_name" label="Nama bisnis" icon="store" required maxlength="100" placeholder="Kopi Senja" autofocus />

            <div class="cashier-field">
                <label for="business_type">Jenis usaha</label>
                <div class="auth-input has-icon">
                    <x-site.icon name="cube" class="auth-input-icon" />
                    <select id="business_type" name="business_type" required
                        @error('business_type') aria-invalid="true" aria-describedby="business_type-error" @enderror>
                        @foreach ($businessTypes as $type)
                            <option value="{{ $type->value }}" @selected(old('business_type', 'cafe') === $type->value)>{{ $type->getLabel() }}</option>
                        @endforeach
                    </select>
                </div>
                @error('business_type') <p id="business_type-error" class="cashier-field-error">{{ $message }}</p> @enderror
            </div>
            </div>

            <x-auth.field name="name" label="Nama Anda" icon="user" required autocomplete="name" maxlength="100" placeholder="Budi Santoso" />
            <x-auth.field name="email" label="Email" type="email" icon="mail" required autocomplete="email" maxlength="150" placeholder="budi@kopisenja.id" />
            <div class="auth-grid">
                <x-auth.field name="password" label="Kata sandi" type="password" icon="lock" required autocomplete="new-password" maxlength="255"
                    placeholder="Kata sandi" />
                <x-auth.field name="password_confirmation" label="Ulangi kata sandi" type="password" icon="lock" required autocomplete="new-password"
                    maxlength="255" placeholder="Ulangi kata sandi" />
            </div>
            <p class="auth-field-hint">Minimal 8 karakter, berisi huruf dan angka.</p>

            <x-auth.turnstile :audience="\App\Services\Security\Turnstile::OWNER" />

            <button type="submit" class="cashier-auth-btn">Buat akun & mulai trial</button>
            <p class="auth-field-hint auth-center">Kami mengirim link verifikasi ke email Anda. Kasir bisa bertransaksi setelah email terverifikasi.</p>
        </form>

        <p class="cashier-auth-foot">Sudah punya akun? <a href="{{ $loginUrl }}" class="auth-link">Masuk</a> · Butuh bantuan? <a href="{{ route('landing') }}#contact" class="auth-link">Hubungi sales</a></p>
    @endif
</x-auth.layout>
