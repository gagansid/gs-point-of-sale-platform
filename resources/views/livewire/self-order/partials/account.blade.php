{{--
    Tab Profil (ADR 0027 §6): kartu profil member + daftar menu akun. Tamu: ajakan Masuk/Daftar.
    Halaman member yang dibuka dari sini menampilkan "Kembali ke meja".
--}}
@php
    $token = $context->table->qr_token;
    $business = $context->tenant->slug;
@endphp
<section class="so-account">
    @if ($member)
        @php($settings = \App\Models\MemberSetting::current())
        @php($tier = $member->tier)
        <article class="so-card so-profile">
            <div class="so-profile-head">
                <span class="so-profile-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($member->name), 0, 1)) }}</span>
                <div class="so-profile-id">
                    <div class="so-profile-title">
                        <strong class="so-profile-name">{{ $member->name }}</strong>
                        @if ($tier)
                            <span class="so-badge is-info">@svg('heroicon-s-star', ['aria-hidden' => 'true']) {{ $tier->name }}</span>
                        @endif
                    </div>
                    <span class="so-muted so-num">{{ \App\Support\Phone::masked($member->phone) }} · <span class="so-mono">{{ $member->code }}</span></span>
                </div>
            </div>
            <div class="so-profile-points">
                <div>
                    <span class="so-muted">Poin</span>
                    <strong class="so-num">{{ number_format($member->points_balance, 0, ',', '.') }}</strong>
                </div>
                <div>
                    <span class="so-muted">Senilai</span>
                    <strong class="so-num">{{ \App\Support\Money::format(\App\Support\Money::mul((string) $settings->redeem_value, max(0, $member->points_balance))) }}</strong>
                </div>
                <div>
                    <span class="so-muted">Pesanan</span>
                    <strong class="so-num">{{ $this->memberOrders->where('status', \App\Enums\OrderStatus::Completed)->count() }}</strong>
                </div>
            </div>
        </article>

        <h2 class="so-account-label" id="so-account-label">Akun</h2>
        <nav class="so-card so-menu-list" aria-labelledby="so-account-label">
            <button type="button" wire:click="show('history')">
                <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-clock')</span>
                <span class="so-list-main"><strong>Riwayat pesanan</strong><span class="so-muted">Semua pesananmu di {{ $context->tenant->name }}</span></span>
                @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
            </button>
            <a href="{{ route('member.home', ['business' => $business]) }}">
                <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-qr-code')</span>
                <span class="so-list-main"><strong>Kartu member & QR</strong><span class="so-muted">Tunjukkan ke kasir, voucher & stempel</span></span>
                @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
            </a>
            <a href="{{ route('member.history', ['business' => $business]) }}">
                <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-star')</span>
                <span class="so-list-main"><strong>Riwayat poin</strong><span class="so-muted">Poin masuk, dipakai, dan kedaluwarsa</span></span>
                @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
            </a>
            <a href="{{ route('member.profile', ['business' => $business]) }}">
                <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-user')</span>
                <span class="so-list-main"><strong>Ubah profil</strong><span class="so-muted">Nama, email, tanggal lahir</span></span>
                @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
            </a>
            <a href="{{ route('member.password.edit', ['business' => $business]) }}">
                <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-key')</span>
                <span class="so-list-main"><strong>Ganti kata sandi</strong><span class="so-muted">Keluar dari perangkat lain</span></span>
                @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
            </a>
        </nav>
    @elseif ($context->loginRequired())
        <article class="so-card">
            <div class="so-empty">
                <span class="so-empty-icon" aria-hidden="true">@svg('heroicon-o-user-circle')</span>
                <p class="so-empty-title">Masuk sebagai member</p>
                <p>Bayar pesanan, kumpulkan poin, dan lihat riwayat pesananmu di {{ $context->tenant->name }}.</p>
                <div class="so-actions">
                    <a class="so-btn is-primary" href="{{ route('self-order.login', ['token' => $token]) }}">Masuk</a>
                    <a class="so-btn is-outline" href="{{ route('self-order.register', ['token' => $token]) }}">Daftar gratis</a>
                </div>
            </div>
        </article>
    @endif

    <h2 class="so-account-label" id="so-other-label">Lainnya</h2>
    <nav class="so-card so-menu-list" aria-labelledby="so-other-label">
        <button type="button" data-so-open="so-about">
            <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-information-circle')</span>
            <span class="so-list-main"><strong>Tentang aplikasi</strong><span class="so-muted">gs.POS v{{ config('app.version') }}</span></span>
            @svg('heroicon-o-chevron-right', ['class' => 'so-menu-list-go', 'aria-hidden' => 'true'])
        </button>
        @if ($member)
            <form method="POST" action="{{ route('self-order.logout', ['token' => $token]) }}">
                @csrf
                <button type="submit" class="is-danger">
                    <span class="so-menu-list-icon" aria-hidden="true">@svg('heroicon-o-arrow-right-start-on-rectangle')</span>
                    <span class="so-list-main"><strong>Keluar</strong><span class="so-muted">Keranjang di meja ini tetap tersimpan</span></span>
                </button>
            </form>
        @endif
    </nav>
</section>
