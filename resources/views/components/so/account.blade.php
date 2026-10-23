{{--
    Menu akun di header halaman pelanggan (ADR 0027): tamu = tombol Masuk + menu ⋮; member = chip (inisial + poin) yang
    membuka menu. Isi: halaman member, Keluar, Tentang aplikasi. <details> native (tanpa Alpine), ditutup oleh x-so.head.
--}}
@props(['tenant', 'member' => null, 'loginUrl' => null, 'registerUrl' => null, 'logoutUrl' => null])
@if (! $member && $loginUrl)
    <a class="so-btn is-outline" href="{{ $loginUrl }}">Masuk</a>
@endif
<details class="so-menu-pop">
    @if ($member)
        <summary class="so-member-chip" aria-label="Menu akun {{ $member->name }}">
            <span class="so-member-chip-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($member->name), 0, 1)) }}</span>
            <span class="so-member-chip-text so-num">{{ number_format($member->points_balance, 0, ',', '.') }} poin</span>
            @svg('heroicon-o-chevron-down', ['class' => 'so-member-chip-caret', 'aria-hidden' => 'true'])
        </summary>
    @else
        <summary class="so-icon-btn is-plain" aria-label="Menu lainnya">@svg('heroicon-o-ellipsis-vertical', ['aria-hidden' => 'true'])</summary>
    @endif
    <div class="so-menu-panel" role="menu">
        @if ($member)
            <div class="so-menu-head">
                <strong>{{ $member->name }}</strong>
                <span class="so-muted so-num">{{ number_format($member->points_balance, 0, ',', '.') }} poin · {{ $tenant->name }}</span>
            </div>
            <a role="menuitem" href="{{ route('member.home', ['business' => $tenant->slug]) }}">@svg('heroicon-o-qr-code', ['aria-hidden' => 'true']) Kartu member & poin</a>
        @elseif ($registerUrl)
            <a role="menuitem" href="{{ $registerUrl }}">@svg('heroicon-o-user-plus', ['aria-hidden' => 'true']) Daftar member</a>
        @endif
        <button type="button" role="menuitem" data-so-open="so-about">@svg('heroicon-o-information-circle', ['aria-hidden' => 'true']) Tentang aplikasi</button>
        @if ($member && $logoutUrl)
            <form method="POST" action="{{ $logoutUrl }}">
                @csrf
                <button type="submit" role="menuitem" class="is-danger">@svg('heroicon-o-arrow-right-start-on-rectangle', ['aria-hidden' => 'true']) Keluar</button>
            </form>
        @endif
    </div>
</details>
