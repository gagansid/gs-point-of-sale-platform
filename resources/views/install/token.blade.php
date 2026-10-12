{{-- Kode instal (opsional, INSTALL_TOKEN) — ADR 0016 --}}
<x-install.layout title="Kode instal" subtitle="Masukkan kode instal yang diberikan bersama paket aplikasi.">
    <form method="POST" action="{{ route('install.token.verify') }}" class="site-form" novalidate>
        @csrf
        <x-site.field name="token" label="Kode instal" type="password" required autocomplete="off" autofocus />
        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Lanjut</button>
    </form>
</x-install.layout>
