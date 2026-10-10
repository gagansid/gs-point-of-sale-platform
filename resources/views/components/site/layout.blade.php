{{-- Kerangka halaman publik gspos.id (halaman depan & login). Warna/huruf sama dengan panel. --}}
@props(['title' => 'gs.POS — Kasir untuk kafe & UMKM', 'description' => null])

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description ?? 'gs.POS: aplikasi kasir dan dashboard untuk kafe, resto, dan UMKM. Split payment, open bill, stok, shift, dan laporan dalam satu sistem.' }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700,800|nunito:800" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="site-body">
    {{ $slot }}
</body>
</html>
