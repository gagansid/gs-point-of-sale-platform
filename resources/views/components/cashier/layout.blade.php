{{-- Kerangka kasir web (Q54): header outlet/kasir/navigasi + konten. Halaman login & pilih outlet memakai x-site.layout. --}}
@props(['title' => 'Kasir web', 'active' => null, 'wide' => false])
@php
    $user = \App\Support\WebCashier::user();
    $outlet = \App\Support\CurrentOutlet::get();
    $links = [
        'pos' => ['Transaksi', route('cashier.home')],
        'orders' => ['Riwayat', route('cashier.orders')],
        'shift' => ['Shift', route('cashier.shift')],
    ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — gs.POS</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body cashier-body">
    <header class="cashier-header">
        <a href="{{ route('cashier.home') }}" class="cashier-brand" aria-label="Kasir web"><x-site.logo /></a>
        <div class="cashier-context">
            <strong>{{ $outlet?->name }}</strong>
            <span>{{ $user?->name }}</span>
        </div>
        <nav class="cashier-nav" aria-label="Menu kasir">
            @foreach ($links as $key => [$label, $url])
                <a href="{{ $url }}" @class(['is-active' => $active === $key]) @if ($active === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <form method="POST" action="{{ route('cashier.logout') }}" class="cashier-logout">
            @csrf
            <button type="submit" class="site-link-button">Keluar</button>
        </form>
    </header>

    @foreach (\App\Models\Announcement::current() as $announcement)
        <div class="cashier-announcement" role="status">
            <strong>{{ $announcement->title }}</strong> {{ $announcement->body }}
        </div>
    @endforeach

    <main @class(['cashier-main', 'cashier-main-wide' => $wide])>
        @if (session('status'))
            <div class="site-alert site-alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->has('cashier'))
            <div class="site-alert site-alert-warning" role="alert">{{ $errors->first('cashier') }}</div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>
