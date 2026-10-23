<x-member.layout title="Kata sandi">
    <section class="so-card">
        <div>
            <h1 class="so-page-title">{{ $member->getAttribute('must_change_password') ? 'Buat kata sandi baru' : 'Ganti kata sandi' }}</h1>
            @if ($member->getAttribute('must_change_password'))
                <p class="so-muted">Anda masuk dengan kata sandi sementara dari kasir. Buat kata sandi Anda sendiri.</p>
            @endif
        </div>

        <form method="POST" action="{{ route('member.password.update', ['business' => $business->slug]) }}" class="so-form" novalidate>
            @csrf
            <label class="so-field">
                <span class="so-label">Kata sandi saat ini</span>
                <input class="so-input" name="current_password" type="password" autocomplete="current-password" required autofocus @error('current_password') aria-invalid="true" @enderror>
                @error('current_password') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Kata sandi baru</span>
                <input class="so-input" name="password" type="password" autocomplete="new-password" required @error('password') aria-invalid="true" @enderror>
                <small class="so-help">Minimal 8 karakter, huruf & angka</small>
                @error('password') <em class="so-error">{{ $message }}</em> @enderror
            </label>
            <label class="so-field">
                <span class="so-label">Ulangi kata sandi baru</span>
                <input class="so-input" name="password_confirmation" type="password" autocomplete="new-password" required>
            </label>
            <button type="submit" class="so-btn is-primary is-block">Simpan</button>
        </form>
    </section>
</x-member.layout>
