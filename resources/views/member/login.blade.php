<x-member.layout title="Masuk">
    <section class="member-card">
        <h1 class="member-title">Masuk member</h1>
        <p class="member-muted">Lihat poin, kode QR untuk kasir, dan riwayat belanja Anda.</p>

        <form method="POST" action="{{ route('member.login.store', ['business' => $business->slug]) }}" class="member-form" novalidate>
            @csrf
            <label class="member-field">
                <span>Nomor HP</span>
                <input name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0812 3456 7890" required autofocus
                    @error('phone') aria-invalid="true" @enderror>
            </label>
            <label class="member-field">
                <span>Kata sandi</span>
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            @error('phone') <p class="member-error" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="member-btn">Masuk</button>
        </form>

        <p class="member-foot">Belum punya akun? <a href="{{ route('member.register', ['business' => $business->slug]) }}">Daftar member</a></p>
        <p class="member-foot member-muted">Lupa kata sandi? Minta kasir mengatur kata sandi sementara.</p>
    </section>
</x-member.layout>
