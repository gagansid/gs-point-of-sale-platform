{{-- Installer tidak bisa menulis .env / storage (ADR 0016) --}}
<x-install.layout title="Folder aplikasi tidak bisa ditulis">
    <div class="site-alert site-alert-warning" role="alert">{{ $message }}</div>
    <p class="site-auth-sub">Ubah izin folder aplikasi, <code>storage</code>, dan <code>bootstrap/cache</code> lewat File Manager cPanel (folder 755, file 644), lalu muat ulang halaman ini.</p>
</x-install.layout>
