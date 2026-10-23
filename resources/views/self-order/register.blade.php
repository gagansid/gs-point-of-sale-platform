{{-- Daftar singkat member dari meja (ADR 0027 K5): nama, No. HP, kata sandi, persetujuan; tanpa OTP. --}}
<x-self-order.layout :context="$context" title="Daftar member">
    <main class="so-main so-auth">
        <a class="so-back" href="{{ route('self-order.login', ['token' => $context->table->qr_token]) }}">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Kembali</a>

        <section class="so-card">
            <header class="so-auth-head">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-user-plus')</span>
                <h1 class="so-page-title">Daftar member</h1>
                <p class="so-muted">Satu menit saja. Kumpulkan poin di semua outlet {{ $context->tenant->name }}.</p>
            </header>

            <form method="POST" action="{{ route('self-order.register.store', ['token' => $context->table->qr_token]) }}" class="so-form" novalidate>
                @csrf
                <x-so.input name="name" label="Nama" icon="heroicon-o-user" autocomplete="name" maxlength="100" placeholder="Nama panggilan" required autofocus />
                <x-so.input name="phone" label="Nomor HP" icon="heroicon-o-device-phone-mobile" type="tel" inputmode="tel" autocomplete="tel" placeholder="0812 3456 7890" maxlength="30" required />
                <x-so.input name="password" label="Kata sandi" icon="heroicon-o-lock-closed" type="password" autocomplete="new-password" maxlength="255" help="Minimal 8 karakter, berisi huruf & angka" required />
                <div class="so-field">
                    <label class="so-check">
                        <input type="checkbox" name="consent" value="1" @checked(old('consent')) @error('consent') aria-invalid="true" @enderror>
                        <span>Saya setuju data saya dipakai {{ $context->tenant->name }} untuk program member</span>
                    </label>
                    @error('consent') <em class="so-error" role="alert">{{ $message }}</em> @enderror
                </div>
                <button type="submit" class="so-btn is-primary is-block">Daftar & lanjutkan</button>
            </form>

            <p class="so-foot">Sudah punya akun? <a href="{{ route('self-order.login', ['token' => $context->table->qr_token]) }}">Masuk</a></p>
        </section>
    </main>
</x-self-order.layout>
