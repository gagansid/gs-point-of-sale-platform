# gs-point-of-sale-platform

Backend POS multi-tenant untuk kafe & UMKM: REST API untuk aplikasi Flutter
(`gs-point-of-sale-app`), dashboard owner (`/dashboard`), dan panel super admin (`/admin`).

Spesifikasi lengkap ada di `docs/SPEC.md`. Baca bagian yang relevan sebelum mengerjakan fitur.
Alur kerja, Definition of Done, dan peta standar ada di `AGENTS.md`; standar rinci
(struktur, coding, API, UI, testing, workflow, dokumentasi) ada di `docs/standards/`.

## Stack

- PHP 8.2, Laravel 12 + MySQL 8 / MariaDB 10.6+ (ADR 0004 — jangan pakai fitur PHP 8.3+)
- Laravel Sanctum 4 (token API per device), Filament 5 (2 panel: `Dashboard`, `Admin`)
- Pest 3, Laravel Pint, Larastan 3 (level 6)
- Target hosting: shared hosting cPanel. Jangan memakai Redis, Docker, atau proses
  yang berjalan terus-menerus. Queue, cache, dan session memakai driver `database`.

## Perintah

```bash
composer install
php artisan migrate --seed
php artisan test            # Pest
./vendor/bin/pint           # format kode
./vendor/bin/phpstan analyse
```

## Aturan arsitektur

- Logika bisnis HANYA di `app/Actions/*` (satu kelas = satu use case, dalam satu
  transaksi DB). Controller API dan Filament Resource hanya memanggil Action.
- Perhitungan total order HANYA di `app/Services/OrderCalculator.php`.
  Server selalu menghitung ulang nominal; nilai dari client diabaikan.
- Setiap model bisnis memakai trait `BelongsToTenant` (`tenant_id` otomatis + global scope).
  Jangan pernah query lintas tenant kecuali di panel `/admin`.
- Primary key UUID (`HasUuids`). ID `orders` dan `payments` dikirim oleh Flutter dan
  berfungsi sebagai idempotency key: jika ID sudah ada, kembalikan data lama
  dengan `meta.idempotent_replay: true`.
- `order_items` dan `order_item_options` menyimpan snapshot nama & harga.
- Nominal uang `DECIMAL(15,2)` di DB, string desimal di JSON (`"62000.00"`).

## Role & permission

- Role tenant: `owner`, `manager`, `supervisor`, `cashier` (enum `App\Enums\UserRole`).
- JANGAN mengecek role langsung (`$user->role === ...`). Selalu pakai permission:
  `$user->can('order.void')`. Pemetaan role -> permission hanya di `UserRole::permissions()`.
- Aksi dengan PIN approval (void, diskon di atas batas) wajib menyimpan `approved_by`
  dan menolak jika approver = pelaku (`SELF_APPROVAL_NOT_ALLOWED`).
- Super admin memakai tabel `admins` dan guard terpisah.

## Standar API

- Prefix `/api/v1`, field `snake_case`, tanggal ISO 8601 UTC.
- Semua respons lewat `App\Support\ApiResponse`:
  - Sukses: `{ success: true, message, data, meta }`
  - Gagal:  `{ success: false, message, error: { code, details }, meta }`
- Error bisnis: lempar `App\Exceptions\BusinessException('KODE_ERROR', 'Pesan', status)`.
  Pemetaan exception -> respons ada di `bootstrap/app.php`.
- Daftar `error.code` resmi ada di `docs/SPEC.md` bagian "Standar respons".
  Jangan membuat kode error baru tanpa menambahkannya ke spesifikasi.
- Pesan `message` dalam Bahasa Indonesia.

## Testing

- Setiap Action dan endpoint baru wajib punya Pest test.
- Test wajib mencakup: sukses, validasi gagal, permission ditolak,
  isolasi tenant (tenant A tidak bisa mengakses data tenant B),
  dan request ganda tetap menghasilkan satu data.

## Git

- Fase MVP: semua pekerjaan di branch `feature/pos` (ADR 0003). Model `main`/`develop` +
  branch per pekerjaan (`feature/{issue}-{deskripsi}`, `fix/...`, `hotfix/...`, `chore/...`) diterapkan nanti.
- Commit: Conventional Commits, contoh `feat(order): add checkout endpoint`.
  Scope: auth, catalog, product, shift, order, payment, report, dashboard, admin, db.
- Jangan commit ke `master`, `main`, atau `develop` secara langsung.
