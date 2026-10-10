{{-- gspos.id/daftar: daftar mandiri → trial (ADR 0009). --}}
<x-site.layout title="Coba gratis — gs.POS">
    <main class="site-auth">
        <div class="site-auth-card site-auth-card-wide">
            <a href="{{ route('landing') }}" class="site-auth-logo" aria-label="gs.POS beranda"><x-site.logo /></a>

            @if (! $open)
                <h1>Pendaftaran sedang ditutup</h1>
                <p class="site-auth-sub">Tim kami siap membantu menyiapkan akun Anda.</p>
                <a href="{{ route('landing') }}#kontak" class="site-btn site-btn-primary site-btn-lg site-btn-block">Hubungi sales</a>
                <p class="site-auth-foot">Sudah punya akun? <a href="{{ $loginUrl }}">Masuk</a></p>
            @else
                <h1>Coba gratis {{ $trialDays }} hari</h1>
                <p class="site-auth-sub">Tanpa kartu kredit. Bisa langsung menyiapkan menu.</p>

                <form method="POST" action="{{ route('signup.store') }}" class="site-form" novalidate>
                    @csrf

                    <div class="site-hp" aria-hidden="true">
                        <label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <div class="site-form-grid">
                        <x-site.field name="business_name" label="Nama bisnis" required placeholder="Kopi Senja" maxlength="100" />
                        <div class="site-field">
                            <label for="business_type">Jenis usaha<span class="site-required" aria-hidden="true">*</span></label>
                            <select id="business_type" name="business_type" required @error('business_type') aria-invalid="true" @enderror>
                                @foreach ($businessTypes as $type)
                                    <option value="{{ $type->value }}" @selected(old('business_type', 'cafe') === $type->value)>{{ $type->getLabel() }}</option>
                                @endforeach
                            </select>
                            @error('business_type') <p class="site-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <x-site.field name="name" label="Nama Anda" required autocomplete="name" placeholder="Budi Santoso" maxlength="100" />
                    <x-site.field name="email" label="Email" type="email" required autocomplete="email" placeholder="budi@kopisenja.id" />

                    <div class="site-form-grid">
                        <div class="site-field">
                            <label for="password">Kata sandi<span class="site-required" aria-hidden="true">*</span></label>
                            <input id="password" name="password" type="password" required autocomplete="new-password" maxlength="255"
                                @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                            @error('password') <p id="password-error" class="site-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="site-field">
                            <label for="password_confirmation">Ulangi kata sandi<span class="site-required" aria-hidden="true">*</span></label>
                            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" maxlength="255">
                        </div>
                    </div>
                    <p class="site-form-note site-form-note-left">Minimal 8 karakter, berisi huruf dan angka.</p>

                    <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Buat akun & mulai trial</button>
                    <p class="site-form-note">Kami mengirim link verifikasi ke email Anda. Kasir bisa bertransaksi setelah email terverifikasi.</p>
                </form>

                <p class="site-auth-foot">Sudah punya akun? <a href="{{ $loginUrl }}">Masuk</a> · Butuh bantuan? <a href="{{ route('landing') }}#kontak">Hubungi sales</a></p>
            @endif
        </div>
    </main>
</x-site.layout>
