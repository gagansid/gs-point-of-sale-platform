<x-member.layout title="Daftar">
    <section class="so-card">
        <div>
            <h1 class="so-page-title">Daftar member</h1>
            <p class="so-muted">Kumpulkan poin di semua outlet {{ $business->name }}.</p>
        </div>

        <form method="POST" action="{{ route('member.register.store', ['business' => $business->slug]) }}" class="so-form" novalidate>
            @csrf
            <label class="so-field">
                <span class="so-label">Nama</span>
                <input class="so-input" name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="100" required autofocus @error('name') aria-invalid="true" @enderror>
                @error('name') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Nomor HP</span>
                <input class="so-input" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0812 3456 7890" required @error('phone') aria-invalid="true" @enderror>
                @error('phone') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Kata sandi</span>
                <input class="so-input" name="password" type="password" autocomplete="new-password" required @error('password') aria-invalid="true" @enderror>
                <small class="so-help">Minimal 8 karakter, huruf & angka</small>
                @error('password') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Ulangi kata sandi</span>
                <input class="so-input" name="password_confirmation" type="password" autocomplete="new-password" required>
            </label>
            <div class="so-field">
                <label class="so-check">
                    <input type="checkbox" name="consent" value="1" @checked(old('consent'))>
                    <span>Saya setuju data saya dipakai {{ $business->name }} untuk program member</span>
                </label>
                @error('consent') <em class="so-error">{{ $message }}</em> @enderror
            </div>
            <button type="submit" class="so-btn is-primary is-block">Daftar</button>
        </form>

        <hr class="so-divider">
        <p class="so-foot">Sudah punya akun? <a href="{{ route('member.login', ['business' => $business->slug]) }}">Masuk</a></p>
    </section>
</x-member.layout>
