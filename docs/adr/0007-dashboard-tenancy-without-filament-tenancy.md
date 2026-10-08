# ADR 0007: Panel /dashboard memakai TenantContext, bukan fitur tenancy Filament

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC "Panel dashboard owner", ADR 0005

## Konteks

SPEC menyebut panel `/dashboard` memakai "fitur tenancy bawaan" Filament. Fitur itu dirancang untuk
user yang menjadi anggota banyak tenant (URL `/dashboard/{tenant}`, pemilih tenant, relasi
`getTenants()`), dan menambahkan scope Eloquent sendiri. Di sistem ini setiap user tepat milik
**satu** tenant, dan isolasi sudah dijamin `TenantScope` fail-closed (ADR 0005).

## Keputusan

- Tidak memakai tenancy Filament. Middleware `SetDashboardTenant` mengisi `TenantContext` dari user
  login; semua query Resource otomatis terisolasi oleh `TenantScope`.
- Middleware didaftarkan sebagai **auth middleware persistent** agar juga berjalan pada request
  Livewire (aksi tabel, simpan form). Tanpa itu data kosong / penyimpanan gagal.
- Tenant ditangguhkan atau user nonaktif → logout + notifikasi, kembali ke halaman login.
- Akses panel: `User::canAccessPanel()` → aktif dan `UserRole::canAccessDashboard()` (owner, manager).
- Setiap Resource punya Policy berbasis permission + cek `tenant_id` (pertahanan berlapis).

## Konsekuensi

- URL dashboard tanpa ID tenant; lebih sederhana untuk owner.
- Jika kelak satu user boleh mengelola banyak bisnis, keputusan ini ditinjau ulang (ADR baru).
- Test Livewire harus men-set `TenantContext` sendiri (request test tidak melewati middleware panel);
  perilaku request sungguhan diverifikasi manual di browser.
