{{--
    <head> halaman pelanggan (pesan dari meja & member, ADR 0027 §6): tema bisnis Q128 — token warna
    (TenantAppearance::css), theme-color bilah status HP, mode terang/gelap/ikuti perangkat tanpa toggle.
--}}
@props(['tenant' => null, 'title'])
{{-- Tanpa bisnis (QR tidak dikenal): merek & warna bawaan gs.POS --}}
@php($mode = $tenant ? \App\Support\TenantAppearance::themeMode($tenant)->value : 'system')
<meta charset="utf-8">
{{-- maximum-scale=1: field 13px (sama dengan panel owner) tidak memicu zoom otomatis iOS; cubit-zoom tetap bisa di iOS --}}
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="{{ $tenant ? \App\Support\TenantAppearance::hex($tenant) : \App\Support\TenantAppearance::PRESETS[\App\Support\TenantAppearance::DEFAULT_COLOR][1] }}">
<title>{{ $title }} · {{ $tenant->name ?? 'gs.POS' }}</title>
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
<script>
    // Perilaku kecil tanpa Alpine (halaman non-Livewire): tampilkan kata sandi, menu akun, dialog Tentang
    document.addEventListener('click', (event) => {
        const reveal = event.target.closest('[data-so-reveal]');
        if (reveal) {
            const input = document.getElementById(reveal.dataset.soReveal);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            reveal.setAttribute('aria-pressed', String(show));
            reveal.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
            reveal.querySelector('[data-show]').hidden = show;
            reveal.querySelector('[data-hide]').hidden = ! show;
        }

        const opener = event.target.closest('[data-so-open]');
        if (opener) {
            opener.closest('details')?.removeAttribute('open');
            document.getElementById(opener.dataset.soOpen)?.showModal();
        }

        document.querySelectorAll('details.so-menu-pop[open]').forEach((menu) => {
            if (! menu.contains(event.target)) menu.removeAttribute('open');
        });

        // Ketuk latar dialog = tutup
        if (event.target instanceof HTMLDialogElement && event.target.classList.contains('so-dialog')) event.target.close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') document.querySelectorAll('details.so-menu-pop[open]').forEach((menu) => menu.removeAttribute('open'));
    });
</script>
@vite(['resources/css/self-order.css'])
@if ($tenant)
    {{ \App\Support\TenantAppearance::css($tenant) }}
@endif
