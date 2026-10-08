# gs-point-of-sale-platform

Backend POS multi-tenant untuk kafe & UMKM: REST API untuk aplikasi Flutter, dashboard owner
(`/dashboard`), dan panel super admin (`/admin`).

- Spesifikasi: [`docs/SPEC.md`](docs/SPEC.md)
- Cara kerja & standar: [`AGENTS.md`](AGENTS.md) → [`docs/`](docs/README.md)

## Kebutuhan lokal

PHP 8.2 (ekstensi `bcmath`, `intl`, `pdo_mysql`, `gd`, `zip`), Composer 2, MySQL 8 / MariaDB 10.6+,
Node.js 20.19+ / 22.12+ (hanya di lokal, untuk build tema Filament).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# buat database `gspos`, sesuaikan DB_* di .env
php artisan migrate --seed        # data demo hanya di APP_ENV=local
npm install && npm run build
php artisan serve
```

Akun demo lokal tercantum di `database/seeders/DemoTenantSeeder.php`.
Panel super admin: `/admin`. Wajib 2FA aktif secara default (bisa diubah di Sistem → Keamanan).
Super admin production dibuat tanpa akun default:

```bash
php artisan pos:create-admin --name="Nama" --email=admin@domain.com
```

## Perintah harian

| Perintah | Fungsi |
|---|---|
| `composer test` | Menjalankan Pest |
| `composer lint` | Cek format (Pint) |
| `composer format` | Memperbaiki format |
| `composer analyse` | Larastan level 6 |
| `composer check` | Ketiganya — wajib hijau sebelum commit |

Admin kehilangan aplikasi authenticator dan recovery code (jalankan di server):

```bash
php artisan pos:admin-reset-2fa admin@domain.com
```
