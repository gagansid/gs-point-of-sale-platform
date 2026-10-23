{{-- Kode instal (wajib, GSPOS-SEC-026) — ADR 0016 --}}
<x-install.layout title="Kode instal" subtitle="Masukkan kode instal untuk memastikan Anda pengelola server ini.">
    <div class="site-alert site-alert-info" role="note">
        @if ($fromEnvironment)
            Kode instal = nilai <code>INSTALL_TOKEN</code> di file <code>.env</code> aplikasi.
        @else
            Buka File Manager cPanel, lalu lihat isi file <code>{{ $codePath }}</code> di folder aplikasi.
            File ini dihapus otomatis setelah instalasi selesai.
        @endif
    </div>

    <form method="POST" action="{{ route('install.token.verify') }}" class="site-form" novalidate>
        @csrf
        <x-site.field name="token" label="Kode instal" type="password" required autocomplete="off" autofocus />
        <button type="submit" class="site-btn site-btn-primary site-btn-lg site-btn-block">Lanjut</button>
    </form>
</x-install.layout>
