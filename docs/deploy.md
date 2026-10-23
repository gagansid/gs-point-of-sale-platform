# Deploy otomatis ke cPanel (GitHub Actions)

> Struktur folder hosting, cron, dan `.env` production: SPEC → *Deploy ke cPanel*. Dokumen ini hanya alur otomatisnya.

## Alur

```
branch kerja ──PR──▶ review + approve ──merge──▶ master
                                                   │
                     .github/workflows/deploy.yml  ▼
        CI (Pint, Larastan, Pest SQLite + MySQL) ── gagal → berhenti, server tidak disentuh
                                                   │ hijau
        build di runner: composer --no-dev, npm run build
                                                   │
        SSH: php artisan down → backup DB ── backup gagal → up, berhenti
                                                   │
        rsync file (tanpa .env, storage/, tests/, docs/)
                                                   │
        SSH: migrate --force → storage:link → optimize → filament:optimize → queue:restart → up
                                                   │
        cek kesehatan HEALTHCHECK_URL (opsional)
```

- Deploy hanya dari `master` (push/merge) atau manual lewat tab **Actions → Deploy → Run workflow**.
- Satu deploy dalam satu waktu (`concurrency`); deploy berikutnya menunggu.
- Hosting tidak perlu `composer`/`npm`/`git`: `vendor/` dan `public/build/` dikirim dari runner. Butuh `rsync` & PHP CLI 8.2+.
- Bila migrasi/cache gagal, situs **tetap dalam mode perawatan** (lebih aman daripada aplikasi jalan dengan skema setengah
  jadi). Periksa `storage/logs/laravel.log` lewat SSH, perbaiki, lalu jalankan ulang workflow atau `php artisan up`.

## Persiapan sekali saja

### 1. Server (cPanel)

1. Aktifkan **SSH Access** (cPanel → SSH Access), catat host, port, user.
2. Instal aplikasi pertama kali lewat form web ([`install.md`](install.md)) atau manual sesuai SPEC → *Deploy ke cPanel*.
   Workflow menolak deploy bila `.env` belum ada.
3. Cari path PHP 8.2+ CLI: `ls /opt/cpanel/ea-php8*/root/usr/bin/php` (isi ke variable `PHP_BIN`).
4. (SaaS) Buat paket langganan bawaan sekali: `php artisan db:seed --class=PlanSeeder --force` — Basic, Pro, Bisnis
   (publik) dan Enterprise (khusus). Paket yang sudah ada tidak diubah; ubah harga/fitur/kuota di admin **Pelanggan → Paket**.
5. Pastikan `mysqldump` tersedia (backup sebelum migrasi). Bila tidak, set variable `DEPLOY_BACKUP=false`
   (tidak disarankan) atau isi `dump.dump_binary_path` di `config/database.php`.

### 2. Kunci SSH khusus deploy

Di komputer lokal (bukan kunci pribadi Anda):

```bash
ssh-keygen -t ed25519 -C "github-deploy-gspos" -f gspos_deploy -N ""
```

- Isi `gspos_deploy.pub` → cPanel → SSH Access → **Import Key** → **Authorize**.
- Isi `gspos_deploy` (privat) → secret `SSH_PRIVATE_KEY`. Hapus file lokal setelah disimpan.
- Host key server untuk secret `SSH_KNOWN_HOSTS` (cocokkan sidik jarinya dengan yang ditampilkan hosting):

```bash
ssh-keyscan -p 22 host.hosting-anda.com
```

### 3. GitHub → Settings → Environments → `production`

| Jenis | Nama | Isi |
|---|---|---|
| Secret | `SSH_HOST` | host SSH, mis. `host.hosting-anda.com` |
| Secret | `SSH_PORT` | port SSH (kosong = 22) |
| Secret | `SSH_USER` | user cPanel |
| Secret | `SSH_PRIVATE_KEY` | kunci privat deploy |
| Secret | `SSH_KNOWN_HOSTS` | keluaran `ssh-keyscan` |
| Secret | `DEPLOY_PATH` | path absolut project, mis. `/home/user/gs-point-of-sale-platform` |
| Variable | `PHP_BIN` | mis. `/opt/cpanel/ea-php82/root/usr/bin/php` (kosong = `php`) |
| Variable | `APP_URL` | `https://gspos.id` (tautan di halaman Actions) |
| Variable | `HEALTHCHECK_URL` | `https://api.gspos.id/v1/system/status` (kosong = dilewati) |
| Variable | `DEPLOY_BACKUP` | `true` (bawaan) / `false` |

Opsional: **Required reviewers** di environment `production` → deploy menunggu persetujuan sekali lagi setelah merge.
**Deployment branches** → hanya `master`.

### 4. GitHub → Settings → Branches (atau Rulesets) → `master`

- Require a pull request before merging → **Require approvals: 1**, dismiss stale approvals.
- Require status checks to pass → `quality` (job CI).
- Block force pushes & deletions; jangan izinkan bypass.

Dengan ini "PR di-approve" → bisa di-merge → otomatis ter-deploy.

## File terkait

| File | Isi |
|---|---|
| `.github/workflows/ci.yml` | CI (juga dipanggil oleh deploy lewat `workflow_call`) |
| `.github/workflows/deploy.yml` | Build + deploy SSH/rsync |
| `scripts/deploy/remote.sh` | Langkah di server: `pre` (down + backup DB), `post` (migrate, cache, up) |
| `scripts/deploy/rsync-exclude.txt` | File yang tidak dikirim / tidak dihapus di server |

## Catatan

- Migration harus aman dijalankan saat situs dalam mode perawatan dan tidak boleh diubah setelah sampai `master`
  (`docs/standards/workflow.md` §8). Backfill data besar diuji dulu di salinan DB produksi (HANDOFF L2).
- Rollback: revert PR → merge → deploy ulang. Migration tidak di-rollback otomatis; bila perlu pulihkan backup DB
  terakhir (`storage/app/backups`, dibuat tepat sebelum migrasi).
- Instalasi pertama bisa lewat form web `/install` (paket zip, ADR 0016) — lihat [`install.md`](install.md); setelah itu
  deploy otomatis ini dipakai untuk rilis berikutnya (`.env` & `storage/` di server tidak disentuh).
- `APP_KEY` produksi tidak pernah sama dengan lokal/staging (GSPOS-SEC-025). Bila server diisi dari paket/data lokal
  beserta `.env`-nya, jalankan sekali `php artisan pos:rotate-key --force && php artisan optimize` di server; ulangi
  bila key diduga bocor. Semua pengguna web masuk ulang; token aplikasi Flutter tetap berlaku.
