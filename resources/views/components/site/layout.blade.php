{{--
    Kerangka halaman publik gspos.id (halaman depan, verifikasi email, installer). Warna/huruf sama dengan panel.
    $seo = halaman untuk mesin pencari & pratinjau tautan (canonical, Open Graph, Twitter); selain itu noindex.
--}}
@props([
    'title' => 'gs.POS — Kasir untuk kafe & UMKM',
    'description' => 'gs.POS: aplikasi kasir dan dashboard untuk kafe, resto, dan UMKM. Split payment, open bill, stok, shift, dan laporan dalam satu sistem.',
    'seo' => false,
])

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="theme-color" content="#1b2a55">
    <link rel="icon" href="{{ asset('images/brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('images/brand/favicon-32x32.png') }}" sizes="32x32" type="image/png">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/apple-touch-icon.png') }}">

    @if ($seo)
        <link rel="canonical" href="{{ url()->current() }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="gs.POS">
        <meta property="og:locale" content="id_ID">
        <meta property="og:url" content="{{ url()->current() }}">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $description }}">
        <meta property="og:image" content="{{ asset('images/brand/og-image.jpg') }}">
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="gs.POS — Kasir cepat, laporan rapi, bisnis lebih tenang.">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $description }}">
        <meta name="twitter:image" content="{{ asset('images/brand/og-image.jpg') }}">
    @else
        <meta name="robots" content="noindex">
    @endif

    {{ $head ?? '' }}

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body">
    {{ $slot }}
</body>
</html>
