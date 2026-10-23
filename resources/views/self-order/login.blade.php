{{-- Masuk member dari meja (ADR 0027 K1): setelah berhasil kembali ke pesanan, keranjang tetap utuh. --}}
<x-self-order.layout :context="$context" title="Masuk">
    <main class="so-main so-auth">
        <a class="so-back" href="{{ $context->url() }}">@svg('heroicon-o-arrow-left', ['aria-hidden' => 'true']) Kembali ke pesanan</a>

        <section class="so-card">
            <header class="so-auth-head">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-user-circle')</span>
                <h1 class="so-page-title">Masuk member</h1>
                <p class="so-muted">Masuk untuk membayar pesanan dan mengumpulkan poin di {{ $context->tenant->name }}.</p>
            </header>

            <form method="POST" action="{{ route('self-order.login.store', ['token' => $context->table->qr_token]) }}" class="so-form" novalidate>
                @csrf
                <x-so.input name="phone" label="Nomor HP" icon="heroicon-o-device-phone-mobile" type="tel" inputmode="tel" autocomplete="tel" placeholder="0812 3456 7890" maxlength="30" required autofocus />
                <x-so.input name="password" label="Kata sandi" icon="heroicon-o-lock-closed" type="password" autocomplete="current-password" maxlength="255" required />
                <button type="submit" class="so-btn is-primary is-block">Masuk</button>
            </form>

            <div class="so-auth-alt">
                <span>Belum punya akun?</span>
                <a class="so-btn is-outline is-block" href="{{ route('self-order.register', ['token' => $context->table->qr_token]) }}">@svg('heroicon-o-user-plus', ['aria-hidden' => 'true']) Daftar member, gratis</a>
            </div>
            <p class="so-muted so-foot">Lupa kata sandi? Minta kasir mengatur kata sandi sementara.</p>
        </section>
    </main>
</x-self-order.layout>
