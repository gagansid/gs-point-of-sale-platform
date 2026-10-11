{{-- Kerangka halaman member (ADR 0013): mobile-first, header bisnis, navigasi bawah bila sudah masuk. --}}
@props(['title' => 'Member', 'active' => null])
@php($member = \App\Support\MemberPortal::member())
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1b2a55">
    <title>{{ $title }} · {{ $business->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body member-body">
    <header class="member-header">
        <div>
            <p class="member-header-label">Member</p>
            <p class="member-header-business">{{ $business->name }}</p>
        </div>
        @if ($member)
            <form method="POST" action="{{ route('member.logout', ['business' => $business->slug]) }}">
                @csrf
                <button type="submit" class="member-header-logout">Keluar</button>
            </form>
        @endif
    </header>

    <main class="member-main">
        @if (session('status'))
            <div class="member-alert" role="status">{{ session('status') }}</div>
        @endif

        {{ $slot }}
    </main>

    @if ($member && ! $member->getAttribute('must_change_password'))
        <nav class="member-nav" aria-label="Menu member">
            @foreach (['home' => ['Beranda', 'heroicon-o-qr-code'], 'history' => ['Riwayat', 'heroicon-o-clock'], 'profile' => ['Profil', 'heroicon-o-user-circle']] as $route => [$label, $icon])
                <a href="{{ route('member.'.$route, ['business' => $business->slug]) }}" @class(['is-active' => $active === $route]) @if ($active === $route) aria-current="page" @endif>
                    @svg($icon, ['aria-hidden' => 'true'])
                    <span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    <p class="member-powered">gs.POS · v{{ config('app.version') }}</p>
</body>
</html>
