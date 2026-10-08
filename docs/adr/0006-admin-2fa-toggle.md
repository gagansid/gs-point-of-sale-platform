# ADR 0006: 2FA super admin yang bisa diaktifkan/dinonaktifkan dari panel

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Keamanan ("Admin panel: sebaiknya 2FA"), `docs/standards/security.md`

## Konteks

Super admin memegang akses lintas tenant sehingga butuh 2FA, tetapi pemilik sistem ingin
kewajiban 2FA bisa dinyalakan/dimatikan. Filament v5 menentukan route & middleware 2FA saat
**registrasi route**; dengan `php artisan optimize` (route cache, wajib saat deploy) perubahan
setelan tidak akan berlaku sampai cache dibersihkan.

## Keputusan

- 2FA memakai aplikasi authenticator (TOTP) bawaan Filament + recovery code. Secret & recovery
  code disimpan terenkripsi (`APP_KEY`).
- Setelan global `security.admin_two_factor_required` di tabel `system_settings`
  (akses lewat `App\Support\SystemSettings`, di-cache dan dihapus saat diubah).
  Default dari `POS_ADMIN_2FA_REQUIRED` (default `true`).
- Panel selalu didaftarkan dengan 2FA "wajib" agar route setup selalu ada; keputusan sebenarnya
  diambil **per request** oleh `EnsureAdminTwoFactorWhenRequired` (turunan middleware Filament).
- Mengubah setelan dilakukan di Sistem → Keamanan dan **wajib konfirmasi kata sandi**, dicatat di
  log (`warning`) dan kolom `updated_by`.
- Setiap admin tetap bisa mengaktifkan/menonaktifkan 2FA miliknya dari halaman profil.
- Pemulihan darurat (kehilangan authenticator & recovery code): `php artisan pos:admin-reset-2fa {email}`
  — hanya dari server.

## Konsekuensi

- Saat setelan OFF, admin yang sudah memakai 2FA tetap diminta kode saat login.
- Saat setelan ON, admin tanpa 2FA diarahkan ke halaman setup sebelum bisa memakai panel.
- Upgrade Filament: cek ulang bahwa `multiFactorAuthenticationRequiredMiddlewareName()` dan
  middleware induknya tidak berubah (dijaga test `AdminPanelAccessTest`).
