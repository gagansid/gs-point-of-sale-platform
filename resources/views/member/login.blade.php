<x-member.layout title="Masuk">
    <section class="so-card">
        <div>
            <h1 class="so-page-title">Masuk member</h1>
            <p class="so-muted">Lihat poin, kode QR untuk kasir, dan riwayat belanja.</p>
        </div>

        <form method="POST" action="{{ route('member.login.store', ['business' => $business->slug]) }}" class="so-form" novalidate>
            @csrf
            <label class="so-field">
                <span class="so-label">Nomor HP</span>
                <input class="so-input" name="phone" type="tel" inputmode="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0812 3456 7890" required autofocus
                    @error('phone') aria-invalid="true" @enderror>
            </label>
            <label class="so-field">
                <span class="so-label">Kata sandi</span>
                <input class="so-input" name="password" type="password" autocomplete="current-password" required>
                @error('phone') <em class="so-error" role="alert">{{ $message }}</em> @enderror
            </label>
            <button type="submit" class="so-btn is-primary is-block">Masuk</button>
        </form>

        <hr class="so-divider">
        <p class="so-foot">Belum punya akun? <a href="{{ route('member.register', ['business' => $business->slug]) }}">Daftar member</a></p>
        <p class="so-muted so-foot">Lupa kata sandi? Minta kasir mengatur kata sandi sementara.</p>
    </section>
</x-member.layout>
