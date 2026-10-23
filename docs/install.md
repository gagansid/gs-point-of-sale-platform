# Instal gs.POS lewat form web

> ADR 0016. Untuk instalasi pertama di hosting/PC baru. Rilis berikutnya: deploy otomatis ([`deploy.md`](deploy.md))
> atau unggah paket baru (lihat *Update* di bawah).

## Yang perlu disiapkan

| Kebutuhan | Keterangan |
|---|---|
| Hosting | cPanel (shared hosting) atau server dengan PHP 8.2+ dan MySQL 8 / MariaDB 10.6+ |
| Ekstensi PHP | bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip |
| Database | Satu database **kosong** + user yang punya semua hak ke database itu |
| Domain | Domain utama; opsional subdomain `app.`, `admin.`, `api.` (semua ke folder `public`) |
| SSL | Disarankan aktif sebelum instal (AutoSSL). Setelah terinstal semua alamat memakai https |
| Paket | `gspos-vX.Y.Z-self_hosted.zip` (jual putus, satu bisnis) atau `-saas.zip`, dari GitHub Releases |

## Langkah

1. **Siapkan hosting**
   - cPanel → MySQL Databases: buat database (mis. `user_gspos`) dan user, lalu *Add User To Database* → **All Privileges**.
   - cPanel → SSL/TLS Status: jalankan AutoSSL untuk domain (dan subdomain bila dipakai).
2. **Unggah & ekstrak paket**
   - File Manager → unggah zip ke folder home (di luar `public_html`), ekstrak → folder `gs-point-of-sale-platform`.
   - Domains → arahkan **Document Root** domain (dan subdomain) ke `gs-point-of-sale-platform/public`.
   - Dari `git clone`: jalankan `composer install --no-dev --optimize-autoloader` dulu (aset `public/build` sudah ada di repo).
3. **Buka domain** di browser → otomatis ke `/install`, yang langsung meminta **kode instal** (wajib, GSPOS-SEC-026):
   - Buka File Manager cPanel → `gs-point-of-sale-platform/storage/app/install-code.txt` (dibuat saat `/install`
     pertama dibuka; format `XXXX-XXXX-XXXX`, tidak peka huruf besar/kecil). File dihapus otomatis setelah instal.
   - Atau tentukan kode sendiri sebelum membuka domain: buat `gs-point-of-sale-platform/.env` berisi satu baris
     `INSTALL_TOKEN=kode-rahasia-anda` (peka huruf besar/kecil; file kode acak tidak dibuat).
   - Kode membuktikan Anda pemegang akses server, sehingga orang yang lebih dulu menemukan domain tidak bisa
     menjalankan installer dengan database & akun miliknya.
4. Isi form:

| Langkah form | Isi |
|---|---|
| Kode instal | Isi `storage/app/install-code.txt`, atau `INSTALL_TOKEN` bila diisi |
| 1. Syarat | Versi PHP, ekstensi, folder bisa ditulis (wajib); HTTPS, symlink, proc_open (peringatan) |
| 2. Database | Host (cPanel: `localhost`), port `3306`, nama database, user, kata sandi → **Tes koneksi** |
| 3. Aplikasi | Nama, URL (`https://…`), edisi (bawaan dari paket), zona waktu outlet, subdomain (opsional), SMTP (opsional) |
| 4. Akun | Super admin (panel admin, kata sandi ≥ 12 karakter + simbol) · bisnis + owner (wajib untuk jual putus) · manager (opsional) |
| 5. Instal | Ringkasan → **Mulai instal**: konfigurasi `.env` → tabel database → akun & data awal → file publik → selesai |

5. **Selesai** → otomatis ke halaman login (owner bila bisnis dibuat; tanpa bisnis → login super admin).
   Super admin wajib mengaktifkan 2FA saat masuk pertama.
6. **Cron** (cPanel → Cron Jobs, setiap menit):
   `cd /home/USER/gs-point-of-sale-platform && php artisan schedule:run >> /dev/null 2>&1`
   (ganti `php` dengan path PHP 8.2+, mis. `/opt/cpanel/ea-php82/root/usr/bin/php`). Wajib untuk email, pesanan meja
   kedaluwarsa, ringkasan harian, dan backup. Cek di admin **Dasbor → Kesehatan sistem**: kartu *Cron / scheduler*
   harus "Berjalan" dalam 1–2 menit setelah cron dipasang.

## Bila ada masalah

| Gejala | Penyebab / solusi |
|---|---|
| File `install-code.txt` tidak ada | Buka `/install` sekali dulu (file dibuat saat itu); pastikan `storage/app` bisa ditulis |
| "Folder aplikasi tidak bisa ditulis" | Izin folder: aplikasi & `storage`, `bootstrap/cache` 755, file 644 |
| Tes koneksi: akses ditolak / database tidak ditemukan | Periksa user sudah ditambahkan ke database dengan All Privileges |
| "Database tidak kosong" | Pakai database baru; installer tidak pernah menimpa data |
| Langkah instal gagal | Pesan + tombol **Coba lagi**; detail di `storage/logs/laravel.log`. Refresh halaman aman (lanjut dari langkah terakhir) |
| Foto produk/profil tidak tampil | Symlink nonaktif: buat link `public/storage` → `storage/app/public` (minta hosting) |
| Tampilan berantakan setelah instal | SSL belum aktif; semua aset memakai https di production |

## Keamanan

- `/install` hanya terbuka sebelum terinstal; setelah selesai dikunci (`storage/app/installed.json`) dan menjawab 404.
- Semua langkah (termasuk Tes koneksi database & proses instal) butuh kode instal; tanpa kode yang berlaku installer
  menolak semua isian (gagal tertutup). Batas 10 percobaan kode per menit per IP; ID session diganti setelah kode benar.
- Menghapus file kunci saja **tidak** membuka installer lagi (aplikasi yang sudah punya `APP_KEY` dianggap terinstal).
  Instal ulang = hapus `.env` + `storage/app/installed.json` **dan** kosongkan database (sengaja manual).
- Kata sandi database, SMTP, dan akun tidak ditampilkan ulang; `.env` ditulis dengan izin 0640.

## Update ke versi baru (tanpa GitHub Actions)

1. Backup database (panel admin → Sistem → Backup, atau phpMyAdmin → Export).
2. Ekstrak paket baru menimpa folder aplikasi, **kecuali** `.env` dan folder `storage` (jangan dihapus).
3. Terminal cPanel: `php artisan down && php artisan migrate --force && php artisan optimize && php artisan up`.

## Membuat paket (tim pengembang)

- Otomatis: push tag `vX.Y.Z` → workflow **Release** menjalankan CI lalu melampirkan
  `gspos-vX.Y.Z-self_hosted.zip`, `gspos-vX.Y.Z-saas.zip`, dan `SHA256SUMS.txt` ke GitHub Release.
- Lokal: `scripts/release/build-package.sh v1.0.0 self_hosted dist` (dari commit terakhir; butuh composer & npm).
