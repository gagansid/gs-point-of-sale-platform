<x-member.layout title="Kata sandi">
    <section class="member-card">
        <h1 class="member-title">{{ $member->getAttribute('must_change_password') ? 'Buat kata sandi baru' : 'Ganti kata sandi' }}</h1>
        @if ($member->getAttribute('must_change_password'))
            <p class="member-muted">Anda masuk dengan kata sandi sementara dari kasir. Buat kata sandi Anda sendiri.</p>
        @endif

        <form method="POST" action="{{ route('member.password.update', ['business' => $business->slug]) }}" class="member-form" novalidate>
            @csrf
            <label class="member-field">
                <span>Kata sandi saat ini</span>
                <input name="current_password" type="password" autocomplete="current-password" required autofocus>
                @error('current_password') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Kata sandi baru</span>
                <input name="password" type="password" autocomplete="new-password" required>
                <small>Minimal 8 karakter, huruf & angka</small>
                @error('password') <em class="member-error">{{ $message }}</em> @enderror
            </label>
            <label class="member-field">
                <span>Ulangi kata sandi baru</span>
                <input name="password_confirmation" type="password" autocomplete="new-password" required>
            </label>
            <button type="submit" class="member-btn">Simpan</button>
        </form>
    </section>
</x-member.layout>
