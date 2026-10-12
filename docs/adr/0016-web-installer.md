# ADR 0016: Installer lewat form web

- Status: Diterima
- Tanggal: 2026-10-12
- Pengambil keputusan: Gagan Suganda
- Terkait: ADR 0009 (edisi `saas` / `self_hosted`, `pos:install`), ADR 0008 (domain), SPEC → *Deploy ke cPanel*,
  `docs/deploy.md` (deploy otomatis setelah terinstal)

## Konteks

Instalasi pertama saat ini manual lewat terminal: salin `.env`, isi database, `key:generate`, `migrate`,
`pos:install`, `pos:create-admin`, `storage:link`. Pembeli edisi jual putus dan tim yang memasang di hosting baru
sering tidak punya akses/keahlian terminal. Dibutuhkan alur instal yang seluruhnya lewat form di browser, lalu
otomatis masuk ke halaman login.

Batasan shared hosting: tanpa proses latar, `max_execution_time` 30–60 detik, kadang tanpa `composer`/`npm`.

## Keputusan

### Alur

```
unggah & ekstrak paket rilis → buka domain → /install
  1. Cek syarat      PHP 8.2+, ekstensi, folder bisa ditulis, mysqldump (peringatan saja)
  2. Database        host, port, nama DB, user, password → Tes koneksi (DB wajib kosong)
  3. Aplikasi        nama aplikasi, URL, edisi, zona waktu, domain/subdomain, email SMTP (opsional, bisa nanti)
  4. Akun            super admin (panel /admin) · bisnis + outlet pertama + owner · manager (opsional)
  5. Instal          progress per langkah: .env → key → migrasi → data awal → akun → storage link → cache
  6. Selesai         installer dikunci → otomatis ke halaman login
```

| Topik | Keputusan |
|---|---|
| Paket rilis | Zip berisi `vendor/` & `public/build/` (dibuat GitHub Actions saat tag), jadi hosting tidak perlu `composer`/`npm`. Dari `git clone` tetap bisa (jalankan `composer install` dulu). |
| Kode instal | **Opsional** (keputusan pemilik). Bila `INSTALL_TOKEN` diisi di `.env` awal / variabel server, form meminta kode itu; bila kosong, installer terbuka tanpa kode dan menampilkan peringatan "selesaikan instalasi segera". |
| Edisi | Dipilih di form langkah 3; bawaannya dari paket (`POS_EDITION` di `.env.example` paket: zip `self_hosted` & `saas`). |
| Bisnis pertama | Wajib untuk `self_hosted` (satu-satunya bisnis, sama dengan `pos:install`). Opsional untuk `saas`. |
| Status terinstal | File `storage/app/installed.json` (versi, waktu). Ada file ini = `/install` 404 selamanya. `storage/` tidak disentuh deploy (`rsync-exclude.txt`). |
| Sebelum terinstal | Semua request web dialihkan ke `/install`; API menjawab `503 MAINTENANCE`. Selama instal: session & cache driver `file` (tabel belum ada), skema URL ikut request (SSL boleh belum aktif), `APP_ENV=production` tanpa debug. |
| Proses panjang | Tiap langkah instal = satu request AJAX (migrasi dijalankan per file) agar tidak kena batas waktu PHP; status di `storage/app/install-state.json` sehingga halaman bisa di-refresh dan dilanjutkan. Gagal = pesan jelas + tombol "Coba lagi" untuk langkah itu. |
| Logika | Installer memanggil Action yang sudah ada (`CreateTenantWithOwner`, pembuatan admin seperti `pos:create-admin`); `pos:install` tetap ada untuk instal lewat terminal. |

### Keamanan

- Installer hanya aktif bila belum terinstal **dan** database tujuan kosong (tidak bisa menimpa data yang ada).
- Nilai `.env` di-escape (kutip, `#`, baris baru ditolak); `.env` ditulis dengan izin `0640`. Password DB & akun tidak
  pernah ditampilkan ulang dan tidak dicatat di log.
- CSRF aktif (session file), throttle Tes koneksi DB & submit (mis. 10/menit per IP).
- `APP_ENV=production`, `APP_DEBUG=false` ditulis otomatis; kata sandi akun mengikuti `Password::defaults()`.
- Super admin wajib mengaktifkan 2FA saat login pertama (ADR 0006).
- Instal ulang: hapus `storage/app/installed.json` **dan** kosongkan database (lewat cPanel, disengaja manual).

## Konsekuensi

- Positif: pemasangan tanpa terminal; satu alur untuk SaaS & jual putus; setelah terinstal, rilis berikutnya lewat
  deploy otomatis (`docs/deploy.md`) atau unggah paket baru + migrasi.
- Negatif: rute & middleware khusus "belum terinstal" yang harus dijaga tetap tertutup setelah instal; menulis `.env`
  butuh izin tulis folder project.
- Netral: aplikasi Flutter tidak berubah.

## Tahapan

| Tahap | Isi |
|---|---|
| N1 | Status terinstal, middleware alih ke `/install`, session/cache `file` sebelum instal, penulis `.env` aman |
| N2 | Form langkah 1–4 (validasi, Tes koneksi, kode instal opsional) |
| N3 | Proses instal per langkah + progress + lanjut setelah refresh, kunci installer, alih ke login |
| N4 | Workflow paket rilis (zip saat tag, `scripts/release/build-package.sh`), `docs/install.md` |

Selesai 2026-10-12. Diuji end-to-end di salinan proyek tanpa `.env` + MySQL 9.5 lokal. Uji di hosting cPanel nyata masih perlu.
