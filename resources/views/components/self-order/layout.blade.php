{{-- Kerangka halaman pesan dari meja (ADR 0014): mobile-first, header bisnis + meja; gaya sama dengan halaman member. --}}
@props(['context', 'title' => 'Pesan'])
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#1b2a55">
    <title>{{ $title }} · {{ $context->tenant->name }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body member-body self-order-body">
    <header class="member-header">
        <div>
            <p class="member-header-label">{{ $context->outlet->name }}</p>
            <p class="member-header-business">{{ $context->tenant->name }}</p>
        </div>
        <span class="self-order-table-badge">{{ $context->table->name }}</span>
    </header>

    <main class="member-main">
        {{ $slot }}
    </main>

    <p class="member-powered">gs.POS · v{{ config('app.version') }}</p>
</body>
</html>
