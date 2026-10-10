# Progres Pengerjaan

> Terakhir diperbarui: 2026-10-10 · Branch: `feature/pos` (ADR 0003) · Commit terakhir: `3937a7b`
> Status kualitas: **632 test** lulus (SQLite), Pint & Larastan level 6 bersih. Uji MySQL untuk fitur
> 9–10 Okt belum dijalankan ulang.

Dokumen ini adalah **titik lanjut** pekerjaan. Baca bagian [Cara melanjutkan](#cara-melanjutkan) dan
[Berikutnya](#berikutnya) sebelum mulai. Urutan rencana mengikuti `docs/SPEC.md` → *Urutan pengerjaan*.

---

## Ringkasan status

| Tahap | Isi | Status | Commit |
|---|---|---|---|
| Standar | AGENTS.md, SPEC.md, standar (struktur, coding, API, UI 15 komponen, testing, workflow, dokumentasi, keamanan), template, ADR | ✅ | `4c5275a` |
| Minggu 1 | Setup Laravel 12 + tooling + CI; envelope API & hardening keamanan; tenant scope fail-closed & permission; tabel akses; panel `/admin` + Tenant + 2FA on/off | ✅ | `ccf5542` … `d7b547e` |
| Minggu 2 | Auth API: status sistem, login email, device token, login PIN (kunci 15 menit), `/auth/me`, logout; middleware versi app & tenant aktif; Scramble | ✅ | `6307425` |
| Minggu 3 | Katalog API (13 endpoint, ETag) + panel `/dashboard` (Produk, Kategori, Grup Opsi) | ✅ | `d42c6e0`, `348d13c` |
| Minggu 4 | Shift, `OrderCalculator`, `/checkout`, nomor order, potong stok | ✅ | `039384c`, `5b3e8cd` |
| Minggu 5 | Open bill, tambah pembayaran, riwayat, detail, struk, void | ✅ | `bc73939` |
| Minggu 6 | Laporan API + beranda (widget) + menu Penjualan, Shift, Laporan + export Excel | ✅ | `51e7146`, `3face3f` |
| UI dashboard | Gaya tabel/form seragam (tab di card, filter lipat, URL bersih), Plus Jakarta Sans, form field & upload gambar | ✅ | `af4ce6c` … `1668b35` |
| Katalog+ | Favorit, stok minimum & menipis, atur urutan kasir, status kategori/grup opsi, sortir API | ✅ | `42f1fad`, `f15f427` |
| Domain | Subdomain `app.`/`admin.`/`api.` + halaman depan & login `gspos.id` (ADR 0008), URL berbahasa Inggris | ✅ | `5d3dcf3`, `83aec78`, `c13c37c` |
| Onboarding | Daftar mandiri + trial, hanya-baca, verifikasi email, checklist + template menu, lead → tenant, lupa kata sandi, edisi jual putus (ADR 0009 langkah 1–6) | ✅ | `abec733` … `3937a7b` |
| **Setelan** | Profil outlet ✅, Karyawan ✅ (PIN + username/kata sandi kasir web), Metode bayar ✅; Perangkat ditunda ke fase tablet; import produk | 🟡 | `1a1b9d9`, `fa95b7e`, `cd2ac45`, `22f2ed2` |
| **Berikutnya** | Multi-outlet M2–M6 (ADR 0010), lalu import produk Excel & kasir web | ⏳ | — |
| Minggu 7 | Menu admin lain: Versi Aplikasi, Pengumuman, Backup, Log Viewer, Queue; rate limit & audit keamanan | ⏳ | — |
| Minggu 8 | Deploy cPanel, uji dengan Flutter, pilot | ⏳ | — |

Endpoint selesai: **44** (lihat `docs/api/README.md`). Keputusan desain: SPEC Q1–Q35, ADR 0001–0009.

---

## Berikutnya

### 1. Setelan (disarankan dikerjakan pertama)

Tanpa ini owner belum bisa menambah karyawan atau mengubah pajak dari aplikasi/dashboard.

**API** (SPEC → *Laporan & setelan*; dokumentasikan di `docs/api/settings.md`):

| Method | Endpoint | Permission | Catatan |
|---|---|---|---|
| GET, PUT | `/outlet` | `outlet.settings` | Pajak, service, inclusive, pembulatan, header/footer struk, batas diskon per role, `timezone` |
| GET, POST, PUT | `/users`, `/users/{id}` | `user.manage` | Tambah kasir/manager, atur PIN (6 digit, hash), nonaktifkan |
| GET, PUT | `/payment-methods`, `/payment-methods/{id}` | `payment_method.manage` | Aktif/nonaktif, nama, `requires_reference` |

Aturan yang wajib diterapkan:

- **Minimal 1 owner aktif** per tenant → `409 LAST_OWNER_REQUIRED` (kode sudah ada di `ErrorCode`).
- Menonaktifkan karyawan / mengganti PIN → **hapus semua tokennya** (akses langsung putus).
- Owner tidak boleh menurunkan role dirinya sendiri bila ia owner terakhir.
- Email karyawan unik global (rule tabel `users`), PIN wajib untuk role yang login PIN.
- Perubahan setelan outlet **tidak** mengubah order lama (sudah aman: order menyimpan tarif sendiri).
- Action baru di `app/Actions/User/*`, `app/Actions/Outlet/*`, `app/Actions/Payment/*`; test 5 kasus wajib.

**Menu `/dashboard`** (`docs/standards/ui/layout.md` §2):

| Menu | Isi | Permission |
|---|---|---|
| Karyawan | CRUD, atur PIN, nonaktifkan | `user.manage` |
| Perangkat | Daftar device, `last_seen_at`, **cabut akses** (isi `revoked_at` + hapus token) | `device.manage` |
| Metode Pembayaran | Aktif/nonaktif, wajib approval code | `payment_method.manage` |
| Pengaturan Outlet | Form satu kolom (`aside` section), logo | `outlet.settings` |
| Produk → Import Excel | Template unduh + import (validasi per baris, laporan error) | `product.manage` |

Catatan import: pakai `maatwebsite/excel` (sudah terpasang); simpan lewat `SaveProduct`;
cegah formula injection juga saat **membaca** (`App\Exports\SafeCell`).

### 1b. Onboarding & edisi (ADR 0009, SPEC Q30–Q34)

| Langkah | Isi | Status |
|---|---|---|
| 1 | Keputusan: ADR 0009, SPEC Q30–Q34, error code `SUBSCRIPTION_EXPIRED` & `EMAIL_NOT_VERIFIED` | ✅ |
| 2 | Setelan admin (`trial_days`, `signup_enabled`); tingkat akses tenant penuh/hanya-baca/diblokir di API & dashboard + banner | ✅ |
| 3 | Daftar sendiri `gspos.id/register` (trial, rate limit, honeypot) + verifikasi email + masuk `app.` via tiket | ✅ |
| S1 | Pengaturan → Profil outlet (dashboard) + `GET/PUT /v1/outlet` | ✅ |
| S2 | Pengaturan → Karyawan + `/v1/users` (PIN aman, putus sesi, owner terakhir, username kasir web) | ✅ |
| S3 | Pengaturan → Metode pembayaran + `/v1/payment-methods` (tunai selalu aktif, urutan) | ✅ |
| 4 | Panduan setup beranda `app.` (profil outlet → kategori → produk, + verifikasi email bila perlu) + template menu Kafe/Warung (`ApplyMenuTemplate`, hanya katalog kosong). Langkah tablet/kasir menyusul | ✅ |
| S4 | Pengaturan → Perangkat (daftar tablet + cabut akses) — ditunda ke fase tablet | Ditunda |
| 5 | "Buat tenant dari lead" + undangan atur kata sandi (3 hari) + `gspos.id/forgot-password` | ✅ |
| 6 | Edisi `self_hosted`: `POS_EDITION`, `php artisan pos:install`, batas satu tenant | ✅ |

### 1c. Multi-outlet (ADR 0010, SPEC Q36–Q41) — sebelum kasir web & deploy

| Langkah | Isi | Status |
|---|---|---|
| M1 | Keputusan: ADR 0010, SPEC Q36–Q41 | ✅ |
| M2 | Data: `outlet_user` (migrasi dari `users.outlet_id`), `outlet_product` (stok & tersedia per outlet, migrasi dari produk), `tenants.max_outlets` | Belum |
| M3 | Akses: outlet yang ditugaskan per user, scope outlet fail-closed, `CurrentOutlet` dari pilihan session (T7) | Belum |
| M4 | Dashboard: Pengaturan → Outlet (daftar/tambah/nonaktifkan), pemilih outlet di topbar, Karyawan pilih outlet | Belum |
| M5 | Stok & katalog per outlet: produk (tersedia/stok per outlet), `AdjustStock`, potong stok checkout, `/catalog`, `PinUsers` | Belum |
| M6 | Laporan & daftar (penjualan, shift, beranda) filter outlet; API `/outlets` | Belum |

### 2. Minggu 7 — panel `/admin` & keamanan

- Resource **Versi Aplikasi** (`app_versions`; cache otomatis terhapus via event model) dan
  **Pengumuman** (tampilkan sebagai banner di `/dashboard` lewat render hook, lihat
  `docs/standards/ui/components/notification.md`).
- Halaman **Backup** (`spatie/laravel-backup`, belum terpasang), **Log Viewer**
  (`opcodesio/log-viewer`, belum terpasang), **Queue** (job gagal + retry), **Maintenance Mode**.
- Resource **Admin** (kelola super admin; tetap tanpa akun default).
- Jadwal di `routes/console.php`: `queue:work --stop-when-empty`, `backup:run`, `backup:clean`,
  `sanctum:prune-expired` (SPEC → Deploy).
- Pasang `sentry/sentry-laravel` (SPEC MVP).
- Jalankan checklist `docs/standards/security.md` §6 (OWASP ZAP baseline ke staging).

### 3. Minggu 8 — deploy

- Panduan deploy cPanel langkah demi langkah (`docs/deploy.md`), `.env` production, cron, symlink storage.
- CSP untuk panel Filament (mode report-only dulu, `security.md` §3).
- Uji end-to-end dengan aplikasi Flutter; pilot klien pertama.

---

## Utang teknis & catatan

| # | Hal | Kapan |
|---|---|---|
| T1 | Widget beranda belum di-cache (wajar untuk volume MVP) | Bila beranda melambat |
| T2 | Gambar produk lama tidak dihapus dari storage saat diganti | Bersama menu Setelan |
| T3 | Struk hanya data JSON; belum ada cetak/preview dari web | Bila dibutuhkan owner |
| T4 | Node.js lokal 20.18 < syarat Vite 20.19 (build tetap berhasil) | Upgrade Node lokal |
| T5 | Laravel 12 security fix sampai Feb 2027 → rencanakan PHP 8.3+ & Laravel 13 (ADR 0004) | Sebelum Feb 2027 |
| T6 | `.env` lokal berisi `POS_ADMIN_2FA_REQUIRED=false` (diubah pemilik repo); test tidak terpengaruh (`phpunit.xml`) | Pastikan **true** di production |
| T7 | Multi-outlet (fase 2): `CurrentOutlet` masih "outlet pertama" | Fase 2 |

---

## Cara melanjutkan

```bash
git checkout feature/pos
composer install            # juga mem-publish aset Filament
npm install && npm run build
php artisan migrate:fresh --seed   # data demo: tenant, katalog, transaksi 7 hari
php artisan serve
composer check              # wajib hijau sebelum commit
```

- Akun demo lokal: `database/seeders/DemoTenantSeeder.php` (owner, manager, kasir PIN, super admin).
  Super admin demo diminta mengatur 2FA saat login bila setelan wajib 2FA aktif.
- Panel: `/dashboard` (owner/manager), `/admin` (super admin). Dokumentasi API: `/docs/api` (lokal).
- Test di MySQL lokal (database `gspos_test`):
  `DB_CONNECTION=mysql DB_DATABASE=gspos_test DB_USERNAME=root php artisan test`.
- Alur kerja & Definition of Done: `AGENTS.md`. Setiap langkah: rencana → test → kode →
  `composer check` (+ MySQL untuk transaksi) → cek UI di browser → dokumen → commit.
- Perbarui dokumen ini di akhir setiap langkah.
