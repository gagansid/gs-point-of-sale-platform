{{--
    <head> halaman pelanggan (pesan dari meja & member, ADR 0027 §6): tema bisnis Q128 — token warna
    (TenantAppearance::css), theme-color bilah status HP, mode terang/gelap/ikuti perangkat tanpa toggle.
--}}
@props(['tenant', 'title'])
@php($mode = \App\Support\TenantAppearance::themeMode($tenant)->value)
<meta charset="utf-8">
{{-- maximum-scale=1: field 13px (sama dengan panel owner) tidak memicu zoom otomatis iOS; cubit-zoom tetap bisa di iOS --}}
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ \App\Support\TenantAppearance::hex($tenant) }}">
<title>{{ $title }} · {{ $tenant->name }}</title>
<script>
    // Mode tampilan bisnis: dipasang sebelum CSS dirender agar tidak berkedip
    (() => {
        const mode = @js($mode);
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const apply = () => document.documentElement.classList.toggle('dark', mode === 'dark' || (mode === 'system' && media.matches));
        apply();
        if (mode === 'system') media.addEventListener('change', apply);
        // Mode menu Kotak/Baris pilihan pelanggan (localStorage), dipasang sebelum render agar tata letak tidak meloncat
        let view = 'grid';
        try { view = localStorage.getItem('gspos.so.view') === 'list' ? 'list' : 'grid'; } catch (e) { /* penyimpanan diblokir: bawaan Kotak */ }
        document.documentElement.dataset.soView = view;
    })();
</script>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=plus-jakarta-sans:400,500,600,700&display=swap" rel="stylesheet">
@vite(['resources/css/self-order.css'])
{{ \App\Support\TenantAppearance::css($tenant) }}
