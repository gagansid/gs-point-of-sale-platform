# gs-point-of-sale-platform

Backend POS multi-tenant untuk kafe & UMKM: REST API untuk aplikasi Flutter, dashboard owner
(`/dashboard`), dan panel super admin (`/admin`).

- Spesifikasi: [`docs/SPEC.md`](docs/SPEC.md)
- Cara kerja & standar: [`AGENTS.md`](AGENTS.md) → [`docs/`](docs/README.md)

## Kebutuhan lokal

PHP 8.2 (ekstensi `bcmath`, `intl`, `pdo_mysql`, `gd`, `zip`), Composer 2, MySQL 8 / MariaDB 10.6+,
Node.js (hanya untuk build aset Filament).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# buat database `gspos`, sesuaikan DB_* di .env
php artisan migrate --seed
npm install && npm run build
php artisan serve
```

## Perintah harian

| Perintah | Fungsi |
|---|---|
| `composer test` | Menjalankan Pest |
| `composer lint` | Cek format (Pint) |
| `composer format` | Memperbaiki format |
| `composer analyse` | Larastan level 6 |
| `composer check` | Ketiganya — wajib hijau sebelum commit |
