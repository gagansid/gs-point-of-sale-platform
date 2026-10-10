{{-- gspos.id/reset-password/{token}: lupa kata sandi / undangan owner (SPEC Q34). --}}
<x-site.layout :title="($invitation ? 'Atur kata sandi' : 'Kata sandi baru').' — gs.POS'">
    <main class="site-auth">
        <div class="site-auth-card">
            <a href="{{ route('landing') }}" class="site-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>
            <h1>{{ $invitation ? 'Atur kata sandi' : 'Buat kata sandi baru' }}</h1>
            <p class="site-auth-sub">{{ $invitation ? 'Satu langkah lagi sebelum masuk ke dashboard.' : 'Sesi lama di semua perangkat akan dikeluarkan.' }}</p>

            <form method="POST" action="{{ route('password.update') }}" class="site-form" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="invitation" value="{{ $invitation ? 1 : 0 }}">

                <div class="site-field">
                    <label for="email">Alamat email</label>
                    <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required readonly autocomplete="username">
                    @error('email') <p class="site-error">{{ $message }}</p> @enderror
                </div>

                <div class="site-field">
                    <label for="password">Kata sandi baru<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="password" name="password" type="password" required autofocus autocomplete="new-password" maxlength="255"
                        @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                    @error('password') <p id="password-error" class="site-error">{{ $message }}</p> @enderror
                </div>

                <div class="site-field">
                    <label for="password_confirmation">Ulangi kata sandi<span class="site-required" aria-hidden="true">*</span></label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" maxlength="255">
                </div>
                <p class="site-form-note site-form-note-left">Minimal 8 karakter, berisi huruf dan angka.</p>

                <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Simpan kata sandi</button>
            </form>
        </div>
    </main>
</x-site.layout>
