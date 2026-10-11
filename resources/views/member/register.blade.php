<x-member.layout title="Daftar">
    <section class="member-card">
        <h1 class="member-title">Daftar member</h1>
        <p class="member-muted">Kumpulkan poin di semua outlet {{ $business->name }}.</p>

        <form method="POST" action="{{ route('member.register.store', ['business' => $business->slug]) }}" class="member-form" novalidate>
            @csrf
            <label class="member-field">
                <span>Nama</span>
                <input name="name" type="text" value="{{ old('name') }}" autocomplete="name" maxlength="100" required autofocus>
                @error('name') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Nomor HP</span>
                <input name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0812 3456 7890" required>
                @error('phone') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Kata sandi</span>
                <input name="password" type="password" autocomplete="new-password" required>
                <small>Minimal 8 karakter, huruf & angka</small>
                @error('password') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Ulangi kata sandi</span>
                <input name="password_confirmation" type="password" autocomplete="new-password" required>
            </label>
            <label class="member-check">
                <input type="checkbox" name="consent" value="1" @checked(old('consent'))>
                <span>Saya setuju data saya dipakai {{ $business->name }} untuk program member</span>
            </label>
            @error('consent') <p class="member-error">{{ $message }}</p> @enderror
            <button type="submit" class="member-btn">Daftar</button>
        </form>

        <p class="member-foot">Sudah punya akun? <a href="{{ route('member.login', ['business' => $business->slug]) }}">Masuk</a></p>
    </section>
</x-member.layout>
