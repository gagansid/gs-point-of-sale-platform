{{-- Kerangka halaman member (ADR 0013, tema bisnis ADR 0027 §6): header bisnis, navigasi bawah bila sudah masuk. --}}
@props(['title' => 'Member', 'active' => null])
@php($member = \App\Support\MemberPortal::member())
<!DOCTYPE html>
<html lang="id">
<head>
    <x-so.head :tenant="$business" :title="$title" />
</head>
<body class="so-body">
    <header class="so-header">
        <div class="so-header-inner">
            <x-so.brand :tenant="$business" sub="Member" />
            @if ($member)
                <form method="POST" action="{{ route('member.logout', ['business' => $business->slug]) }}">
                    @csrf
                    <button type="submit" class="so-btn is-outline">@svg('heroicon-o-arrow-right-start-on-rectangle', ['aria-hidden' => 'true']) Keluar</button>
                </form>
            @endif
        </div>
    </header>

    <main class="so-main">
        @if (session('status'))
            <div class="so-alert is-success" role="status">@svg('heroicon-o-check-circle', ['aria-hidden' => 'true']) <span>{{ session('status') }}</span></div>
        @endif

        {{ $slot }}

        <p class="so-powered">gs.POS · v{{ config('app.version') }}</p>
    </main>

    @if ($member && ! $member->getAttribute('must_change_password'))
        <nav class="so-tabbar" aria-label="Menu member">
            <div class="so-tabbar-inner">
                @foreach (['home' => ['Beranda', 'heroicon-o-qr-code'], 'history' => ['Riwayat', 'heroicon-o-clock'], 'profile' => ['Profil', 'heroicon-o-user-circle']] as $route => [$label, $icon])
                    <a href="{{ route('member.'.$route, ['business' => $business->slug]) }}" @class(['is-active' => $active === $route]) @if ($active === $route) aria-current="page" @endif>
                        @svg($icon, ['aria-hidden' => 'true'])
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        </nav>
    @endif
</body>
</html>
