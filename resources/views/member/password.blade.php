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
            <x-so.input name="current_password" label="Kata sandi saat ini" icon="heroicon-o-lock-closed" type="password" autocomplete="current-password" maxlength="255" required autofocus />
            <x-so.input name="password" label="Kata sandi baru" icon="heroicon-o-key" type="password" autocomplete="new-password" maxlength="255" help="Minimal 8 karakter, berisi huruf & angka" required />
            <x-so.input name="password_confirmation" label="Ulangi kata sandi baru" icon="heroicon-o-key" type="password" autocomplete="new-password" maxlength="255" required />
            <button type="submit" class="so-btn is-primary is-block">Simpan</button>
        </form>
    </section>
</x-member.layout>
