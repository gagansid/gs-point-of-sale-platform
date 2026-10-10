# Spesifikasi gs-point-of-sale-platform

> Sumber: `Spesifikasi gs-point-of-sale-platform.pdf` (8 Okt 2026), dikonversi ke Markdown agar
> bisa di-review lewat PR. **File ini adalah sumber kebenaran**; PDF hanya arsip.
> Perubahan spesifikasi wajib lewat PR dengan label `spec` (lihat `docs/standards/documentation.md`).
> Pertanyaan yang belum terjawab dicatat di bagian [Keputusan & pertanyaan terbuka](#keputusan--pertanyaan-terbuka).

## Ringkasan

`gs-point-of-sale-platform` adalah satu aplikasi Laravel yang menjalankan seluruh sisi server POS:
REST API untuk aplikasi Flutter, dashboard web untuk owner, dan panel maintenance untuk super admin.
Semua bagian memakai model, aturan bisnis, dan database yang sama.

**Cakupan MVP:** multi-tenant dengan 1 outlet per bisnis, kategori & produk dengan opsi tambahan,
kasir dengan split payment (cash, QRIS, transfer, debit, kredit dicatat manual), stok sederhana,
shift, void, dan laporan dasar.

**Fase 2:** mode darurat offline, integrasi payment gateway, multi-outlet, resep, promo, tabel meja,
audit log (`spatie/laravel-activitylog`).

**Hosting:** shared hosting cPanel untuk development dan pilot 1–3 klien, lalu pindah ke VPS region
Indonesia tanpa mengubah kode.

| Bagian | Route | Pengguna | Autentikasi |
|---|---|---|---|
| Halaman depan | `gspos.id` | Calon pelanggan (penjualan + hubungi sales) | — |
| REST API v1 | `api.gspos.id/v1/...` | Aplikasi `gs-point-of-sale-app` (kasir & owner) | Laravel Sanctum token per device |
| Dashboard | `app.gspos.id` (mis. `/products`) | Owner / manager tiap bisnis | Session (Filament), cookie hanya untuk subdomain ini |
| Maintenance | `admin.gspos.id` | Super admin (pengelola sistem) | Session (Filament), tabel user terpisah, cookie terpisah |

Subdomain diatur lewat env `POS_APP_DOMAIN`, `POS_ADMIN_DOMAIN`, `POS_API_DOMAIN` (ADR 0008). Bila
kosong, semua di satu domain: `/dashboard`, `/admin`, `/api/v1` (dipakai test). URL lama di domain utama
dialihkan ke subdomain baru.

## Kebutuhan server

| Komponen | Minimal | Lokasi cek di cPanel |
|---|---|---|
| PHP | 8.2+ (Laravel 12, ADR 0004) | Select PHP Version / MultiPHP Manager |
| Ekstensi PHP | bcmath, ctype, curl, dom, fileinfo, gd, intl, mbstring, openssl, pdo_mysql, tokenizer, xml, zip | Select PHP Version → Extensions |
| Database | MySQL 8.0+ atau MariaDB 10.6+ | MySQL Databases / phpMyAdmin |
| Akses SSH / Terminal | Sangat disarankan | Terminal / SSH Access |
| Composer | Versi 2 | Terminal (`composer -V`) |
| Cron Jobs | Bisa berjalan setiap menit | Cron Jobs |
| SSL | Aktif untuk domain/subdomain | SSL/TLS Status (AutoSSL) |
| Resource | RAM ≥ 1 GB, Entry Process ≥ 20 | Resource Usage |

Ekstensi `intl` wajib untuk Filament. Node.js tidak dibutuhkan di server; aset frontend di-build di lokal.

## Teknologi & package

Intinya Laravel + MySQL + Filament; package lain ditambahkan hanya jika menghemat pekerjaan nyata.

| Package | Fungsi | Fase |
|---|---|---|
| `laravel/framework` | Framework utama | MVP |
| `laravel/sanctum` | Token API per device untuk Flutter | MVP |
| `filament/filament` | Panel `/dashboard` dan `/admin` | MVP |
| `dedoc/scramble` | Dokumentasi OpenAPI otomatis untuk tim Flutter | MVP |
| `spatie/laravel-backup` | Backup database + file terjadwal via cron | MVP |
| `sentry/sentry-laravel` | Error tracking | MVP |
| `maatwebsite/excel` | Export laporan ke Excel | MVP |
| `opcodesio/log-viewer` | Membaca log Laravel dari panel admin | MVP |
| `spatie/laravel-activitylog` | Audit log (void, ubah harga, diskon manual) | Fase 2 |
| `pestphp/pest` | Testing | Dev |
| `laravel/pint` | Format kode otomatis (PSR-12) | Dev |
| `larastan/larastan` | Static analysis | Dev |

Sengaja **tidak** dipakai di MVP: Redis (diganti driver `database` untuk queue, cache, session),
package multi-tenancy pihak ketiga, package role/permission.

## Arsitektur

Logika bisnis ditulis sekali di kelas Action, lalu dipanggil oleh API controller maupun Filament.

| Lapisan | Tanggung jawab | Contoh |
|---|---|---|
| Route + Middleware | Auth, tenant aktif, rate limit, versi app | `auth:sanctum`, `EnsureTenantActive` |
| Form Request | Validasi input API | `CheckoutRequest` |
| Controller / Filament Resource | Memanggil Action, mengembalikan respons | `OrderController@checkout` |
| Action | Satu use case bisnis dalam satu transaksi DB | `CheckoutOrder`, `VoidOrder`, `CloseShift` |
| Service | Perhitungan yang dipakai banyak Action | `OrderCalculator`, `OrderNumberGenerator` |
| Model + Global Scope | Akses data, isolasi tenant otomatis | `Order`, trait `BelongsToTenant` |
| API Resource | Format JSON keluar | `OrderResource` |

### Multi-tenant

Satu database, setiap tabel bisnis punya `tenant_id` (dan `outlet_id` bila relevan). Trait
`BelongsToTenant` mengisi `tenant_id` otomatis saat insert dan menambahkan global scope saat query.

Isolasi bersifat **fail-closed** (ADR 0005): tanpa tenant context, query tidak mengembalikan data.
Implementasi: `app/Models/Concerns/BelongsToTenant.php` + `app/Models/Scopes/TenantScope.php`.

```php
// Ringkasan perilaku
TenantContext::set($tenantId);          // dilakukan middleware `tenant` setelah login
Product::query()->get();                 // hanya produk tenant aktif
Product::query()->create([...]);         // tenant_id terisi otomatis; tenant lain ditolak
Product::allTenants()->count();          // lintas tenant — HANYA panel /admin & command sistem
Product::forTenant($id)->get();          // satu tenant tertentu dari panel /admin
```

### Autentikasi

| Pengguna | Cara login | Hasil |
|---|---|---|
| Owner / manager di app | Email + password | Token Sanctum, sekaligus bisa mendaftarkan device |
| Device kasir | Didaftarkan owner lewat `POST /devices` | Device token (Sanctum, ability `device`), dipakai sebelum kasir login (ADR 0002) |
| Kasir di app | Pilih nama + PIN 6 digit, hanya di device terdaftar (header device token) | Token Sanctum berumur 1 shift (maks. 16 jam) |
| Owner di web | Email + password | Session `/dashboard` |
| Super admin | Email + password (tabel `admins`) | Session `/admin` |

## Role & permission

Kode tidak pernah mengecek role secara langsung; semua pengecekan memakai permission
(`$user->can('order.void')`). Pemetaan role → permission hanya ada di enum `UserRole`.

| Role | Fokus |
|---|---|
| `owner` | Bisnis secara keseluruhan |
| `manager` | Toko: produk, stok, laporan |
| `supervisor` | Shift: approval, kas, pengawasan kasir |
| `cashier` | Transaksi |

✅ = boleh, ❌ = tidak boleh, 🔑 = boleh dengan PIN approval.

| Permission | Fungsi | Owner | Manager | Supervisor | Kasir |
|---|---|:-:|:-:|:-:|:-:|
| `order.create` | Checkout, open bill, tambah pembayaran | ✅ | ✅ | ✅ | ✅ |
| `order.view_own` | Transaksi di shift sendiri | ✅ | ✅ | ✅ | ✅ |
| `order.view_all` | Semua transaksi (supervisor: hari ini) | ✅ | ✅ | ✅ | ❌ |
| `order.reprint` | Cetak ulang struk | ✅ | ✅ | ✅ | ✅ |
| `order.discount` | Diskon manual sampai batas role | ✅ | ✅ | ✅ | ✅ |
| `order.discount_over_limit` | Diskon di atas batas | ✅ | ✅ | ✅ | 🔑 |
| `order.void` | Batalkan transaksi | ✅ | ✅ | ✅ | 🔑 |
| `shift.operate` | Buka/tutup shift sendiri | ✅ | ✅ | ✅ | ✅ |
| `shift.view_all` | Semua shift & selisih kas | ✅ | ✅ | ✅ | ❌ |
| `shift.force_close` | Tutup paksa shift kasir lain | ✅ | ✅ | ✅ | ❌ |
| `product.toggle_available` | Tandai menu habis/tersedia | ✅ | ✅ | ✅ | ❌ |
| `product.manage` | Kategori, produk, opsi | ✅ | ✅ | ❌ | ❌ |
| `stock.adjust` | Penyesuaian stok | ✅ | ✅ | ❌ | ❌ |
| `report.view` | Lihat laporan | ✅ | ✅ | ❌ | ❌ |
| `report.export` | Export Excel | ✅ | ✅ | ❌ | ❌ |
| `user.manage` | Karyawan & PIN | ✅ | ❌ | ❌ | ❌ |
| `device.manage` | Perangkat | ✅ | ❌ | ❌ | ❌ |
| `payment_method.manage` | Metode bayar | ✅ | ❌ | ❌ | ❌ |
| `outlet.settings` | Pajak, service, pembulatan, struk | ✅ | ❌ | ❌ | ❌ |

```php
enum UserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Supervisor = 'supervisor';
    case Cashier = 'cashier';

    /** Satu-satunya tempat pemetaan role -> permission. */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => ['*'],
            self::Manager => ['order.*', 'shift.*', 'product.*', 'stock.*', 'report.*'],
            self::Supervisor => ['order.*', 'shift.*', 'product.toggle_available'],
            self::Cashier => [
                'order.create', 'order.view_own', 'order.reprint',
                'order.discount', 'shift.operate',
            ],
        };
    }

    public function allows(string $permission): bool
    {
        // Wildcard: 'order.*' mencakup 'order.void'
        return collect($this->permissions())
            ->contains(fn (string $p) => Str::is($p, $permission));
    }
}

// AppServiceProvider::boot() — permission hanya dari role; ability Policy diteruskan (ADR 0005)
Gate::before(function (mixed $user, string $ability): ?bool {
    if (! $user instanceof User || ! UserRole::isPermission($ability)) {
        return null;
    }

    // Data rusak (role kosong/tidak dikenal) = ditolak, bukan error
    $role = $user->getAttribute('role');

    return $role instanceof UserRole && $role->allows($ability);
});
```

**Alur PIN approval (🔑):** kasir menekan void atau diskon di atas batas → app meminta PIN →
request membawa `approver_user_id` + `approver_pin`. Server memverifikasi PIN, memastikan approver
punya permission tersebut, dan menolak jika approver = kasir yang sama. Order menyimpan `voided_by`
dan `approved_by`.

Batas diskon disimpan per outlet di `discount_limits`, contoh `{"cashier": 10, "supervisor": 25}`
(persen). Setiap tenant wajib punya minimal 1 owner aktif.

## Struktur folder

Lihat versi lengkap beserta aturan penempatan file di
[`docs/standards/project-structure.md`](standards/project-structure.md).

## Database

19 tabel bisnis. Semua primary key UUID berurutan (`HasUuids`), nominal uang `DECIMAL(15,2)`,
tabel master memakai soft delete.

| Kelompok | Tabel | Kolom penting |
|---|---|---|
| Sistem | `admins` | name, email, password, last_login_at |
| Sistem | `app_versions` | platform, min_version, latest_version, force_update |
| Sistem | `announcements` | title, body, starts_at, ends_at (banner ke semua tenant) |
| Sistem | `sales_leads` | name, business_name, phone, email, city, business_type, message, status (new/contacted/won/lost), notes, ip_hash — form "Hubungi sales" di gspos.id, data platform (tanpa tenant), dikelola di panel admin (Q29) |
| Sistem | `system_settings` | key, value (JSON), updated_by — setelan global dari panel `/admin`, mis. wajib 2FA (ADR 0006) |
| Akses | `tenants` | name, slug, business_type (cafe/retail/other), status (trial/active/suspended), subscription_ends_at |
| Akses | `outlets` | tenant_id, code, name, address, tax_rate, service_charge_rate, tax_inclusive, rounding, receipt_header, receipt_footer, discount_limits (JSON), timezone (default `Asia/Jakarta`, ADR 0001) |
| Akses | `users` | tenant_id, outlet_id, name, email (unik global, boleh kosong untuk kasir), password, pin (hash), pin_failed_attempts, pin_locked_until, role, is_active, last_login_at |
| Akses | `devices` | tenant_id, outlet_id, name, device_uid (unik per tenant), platform, app_version, last_seen_at, revoked_at |
| Produk | `categories` | tenant_id, name, sort_order, is_active (Q27) |
| Produk | `products` | tenant_id, category_id (nullable), name, sku, barcode, price, cost_price, track_stock, stock_qty, min_stock (batas stok menipis, Q24), image_path, is_active, is_available (tanda habis, Q12), is_favorite (favorit outlet, Q25), sort_order (urutan tampil kasir) |
| Produk | `option_groups` | tenant_id, name (Ukuran, Gula, Topping), min_select, max_select, is_active (Q27) |
| Produk | `options` | tenant_id (Q13), option_group_id, name, price_delta, sort_order |
| Produk | `product_option_groups` | product_id, option_group_id, sort_order |
| Transaksi | `shifts` | tenant_id, outlet_id, device_id, opened_by, closed_by, opening_cash, expected_cash, actual_cash, difference, status, close_note, open_device_key (unik: 1 shift terbuka per device), opened_at, closed_at |
| Transaksi | `order_sequences` | outlet_id, date, last_number (penomoran order harian) |
| Transaksi | `orders` | tenant_id, outlet_id, shift_id, device_id, user_id, order_number, order_type, table_label, status, notes, discount_type, discount_value, order_discount, subtotal, discount_total (item + order), service_total, tax_total, rounding, grand_total, paid_total, change_total, service_rate, tax_rate, tax_inclusive (setelan saat transaksi), void_reason, voided_by, void_approved_by, voided_at, approved_by (approver diskon), completed_at |
| Transaksi | `order_items` | order_id, product_id, product_name, unit_price, options_total, qty, discount, line_total, notes |
| Transaksi | `order_item_options` | order_item_id, option_id, option_name, price_delta |
| Pembayaran | `payment_methods` | tenant_id, name, category (cash/qris/transfer/debit/credit), requires_reference, is_active, sort_order — 5 metode bawaan dibuat untuk setiap tenant baru |
| Produk | `stock_movements` | tenant_id, product_id, user_id, type (adjustment/sale/void_return), qty_change, qty_after, reason, reference_id — riwayat stok (Q14) |
| Pembayaran | `payments` | tenant_id, order_id, payment_method_id, user_id, category, amount (dipakai membayar), tendered (uang diterima), change, reference (approval code), status |
| Sistem Laravel | `personal_access_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs` | Bawaan Laravel / Sanctum |

Nilai enum status (keputusan Q7):

| Kolom | Nilai |
|---|---|
| `orders.status` | `open` (open bill), `completed`, `voided` |
| `orders.order_type` | `takeaway`, `dine_in` |
| `payments.status` | `paid`, `voided` |
| `shifts.status` | `open`, `closed`, `force_closed` |

Catatan desain:

- Super admin production dibuat dengan `php artisan pos:create-admin` (kata sandi ditanyakan, tanpa akun default).
  `DemoTenantSeeder` (tenant, outlet, 4 karyawan, device, admin demo) hanya berjalan di local/testing.

- ID `orders` dan `payments` dibuat oleh Flutter dan berfungsi sebagai idempotency key.
- `order_items` dan `order_item_options` menyimpan snapshot nama dan harga.
- `table_label` cukup teks bebas ("Meja 5") di MVP.
- Ukuran minuman (Regular/Large) dibuat sebagai `option_group` dengan `min_select = 1`.

## Aturan bisnis

Server adalah sumber kebenaran: Flutter hanya mengirim produk, opsi, qty, dan diskon; server
menghitung ulang semua nominal dengan `OrderCalculator`. Flutter memakai rumus yang sama hanya
untuk pratinjau.

### Urutan perhitungan total

1. Harga item = (harga produk + total opsi) × qty − diskon item
2. Subtotal = jumlah semua harga item
3. Dasar = subtotal − diskon order
4. Service charge = dasar × tarif service outlet
5. Pajak = (dasar + service charge) × tarif pajak outlet (atau diekstrak dari harga jika `tax_inclusive`)
6. Pembulatan sesuai setelan outlet (misal ke Rp100 terdekat)
7. Grand total = dasar + service charge + pajak + pembulatan

### Pembayaran

- Satu order boleh punya banyak payment (split). Order selesai ketika `paid_total ≥ grand_total`.
- Hanya cash yang boleh melebihi sisa tagihan; kelebihannya menjadi kembalian.
- QRIS, transfer, debit, dan kredit di MVP berstatus `paid` saat kasir konfirmasi.
  Debit dan kredit wajib mengisi `reference` (approval code EDC).
- `payments.id` dikirim oleh Flutter; jika ID sudah ada, server mengembalikan data lama.

### Nomor order

Format `{kode outlet}-{YYMMDD}-{urut 4 digit}`, contoh `JKT01-261008-0042`. `YYMMDD` dan
`order_sequences.date` memakai **tanggal lokal outlet** (ADR 0001). Server mengambil nomor
dari `order_sequences` dengan `SELECT ... FOR UPDATE`.

### Stok

- Hanya produk dengan `track_stock = true` yang dikurangi, tepat saat order selesai.
- Stok boleh minus; dashboard menandai produk dengan stok ≤ 0.
- Void order mengembalikan stok.

### Shift & void

- Order hanya bisa dibuat jika device punya shift berstatus `open`.
- Tutup shift: `expected_cash` = kas awal + penjualan cash − kembalian, dibandingkan dengan `actual_cash`.
- Void hanya untuk order di shift yang masih terbuka, wajib alasan, kasir butuh PIN owner/manager/supervisor.

## API v1

Base URL `/api/v1`, JSON, header `Authorization: Bearer {token}` dan `X-App-Version: {versi}`.
Kolom Role: O = owner, M = manager, S = supervisor, K = kasir (di kode dicek lewat permission).
Standar teknis: [`docs/standards/api/`](standards/api/README.md).

### Auth & sistem

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/system/status` | Publik | Status maintenance, versi minimal app, pengumuman |
| POST | `/auth/login` | O, M | Login email + password |
| POST | `/devices` | O | Daftarkan device ke outlet, mengembalikan device token |
| GET | `/devices/{uid}/cashiers` | Device token | Daftar nama kasir untuk layar PIN |
| POST | `/auth/pin-login` | K (dengan device token) | Login PIN di device terdaftar |
| GET | `/auth/me` | Semua | User, tenant, outlet, role, setelan outlet |
| POST | `/auth/logout` | Semua | Hapus token |

### Katalog & produk

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/catalog` | Semua | Kategori, produk, opsi, metode bayar dalam satu respons (ETag) |
| GET | `/products?search=&category_id=&sort=` | Semua | Cari produk; `sort=price` naik, `sort=-price` turun (kolom allowlist) |
| GET | `/products/barcode/{code}` | Semua | Cari produk via barcode |
| POST, PUT, DELETE | `/categories`, `/categories/{id}` | O, M | Kelola kategori |
| POST, PUT, DELETE | `/products`, `/products/{id}` | O, M | Kelola produk |
| POST | `/products/{id}/stock` | O, M | Penyesuaian stok (tambah/kurang + alasan) |
| POST, PUT, DELETE | `/option-groups`, `/option-groups/{id}` | O, M | Kelola grup opsi beserta opsinya |
| PATCH | `/products/{id}/availability` | O, M, S | Tandai menu habis / tersedia |

### Shift

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/shifts/current` | Semua | Shift terbuka di device ini |
| POST | `/shifts` | Semua | Buka shift + kas awal |
| POST | `/shifts/{id}/close` | Semua | Tutup shift + kas aktual, kembalikan ringkasan |
| GET | `/shifts/{id}/summary` | Semua | Ringkasan penjualan per metode bayar |
| POST | `/shifts/{id}/force-close` | O, M, S | Tutup paksa shift kasir yang lupa ditutup / device rusak |

### Order & pembayaran

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| POST | `/checkout` | Semua | Buat order + bayar sekaligus (takeaway/retail) |
| PUT | `/orders/{id}` | Semua | Simpan / ubah open bill (dine-in) |
| POST | `/orders/{id}/payments` | Semua | Tambah pembayaran ke open bill |
| GET | `/orders?date=&status=&shift_id=` | Semua | Riwayat transaksi |
| GET | `/orders/{id}` | Semua | Detail order |
| GET | `/orders/{id}/receipt` | Semua | Data struk siap cetak |
| POST | `/orders/{id}/void` | O, M, S (K dengan PIN approver) | Batalkan order |

Contoh `POST /checkout`:

```json
{
  "id": "0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a",
  "order_type": "takeaway",
  "table_label": null,
  "notes": null,
  "discount": { "type": "fixed", "value": 5000 },
  "items": [
    { "product_id": "0192f3a1-...", "qty": 2, "option_ids": ["0192f3a1-..."], "notes": "less sugar" }
  ],
  "payments": [
    { "id": "0192f3a2-...", "payment_method_id": "0192f3a1-...", "amount": 50000, "tendered": 50000 },
    { "id": "0192f3a2-...", "payment_method_id": "0192f3a1-...", "amount": 12000, "reference": "A12345" }
  ]
}
```

### Laporan & setelan

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/reports/summary?from=&to=` | O, M | Omzet, jumlah transaksi, rata-rata, diskon, pajak |
| GET | `/reports/products?from=&to=` | O, M | Penjualan per produk |
| GET | `/reports/payment-methods?from=&to=` | O, M | Penjualan per metode bayar |
| GET, PUT | `/outlet` | O | Pajak, service charge, pembulatan, header/footer struk |
| GET, POST, PUT | `/users`, `/users/{id}` | O | Kelola kasir & manager |
| GET, PUT | `/payment-methods`, `/payment-methods/{id}` | O | Aktif/nonaktifkan metode bayar |

## Standar respons

Envelope tunggal: `success`, `message`, `data` atau `error`, dan `meta`. Flutter bercabang
berdasarkan `error.code`, bukan teks `message`. Detail & contoh:
[`docs/standards/api/response-envelope.md`](standards/api/response-envelope.md).

### Daftar `error.code` resmi

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Token tidak valid / kedaluwarsa |
| 401 | `INVALID_PIN` | PIN kasir atau approver salah |
| 403 | `DEVICE_NOT_REGISTERED` | Device belum terdaftar, dicabut, atau beda tenant |
| 403 | `FORBIDDEN` | Tidak punya permission |
| 403 | `TENANT_SUSPENDED` | Tenant diblokir (ditangguhkan) |
| 403 | `SUBSCRIPTION_EXPIRED` | Trial/langganan habis: hanya-baca, perubahan & transaksi ditolak (Q32) |
| 403 | `EMAIL_NOT_VERIFIED` | Checkout/pembayaran sebelum email owner diverifikasi (Q31) |
| 403 | `APPROVAL_REQUIRED` | Aksi butuh PIN approver |
| 403 | `SELF_APPROVAL_NOT_ALLOWED` | Approver sama dengan pelaku |
| 404 | `NOT_FOUND` | Data tidak ditemukan / milik tenant lain |
| 409 | `SHIFT_NOT_OPEN` | Transaksi tanpa shift terbuka |
| 409 | `ORDER_ALREADY_CLOSED` | Mengubah order yang sudah selesai/void |
| 409 | `LAST_OWNER_REQUIRED` | Menonaktifkan/menurunkan role owner aktif terakhir |
| 422 | `VALIDATION_ERROR` | Input tidak valid (detail per field di `error.details`) |
| 422 | `PAYMENT_EXCEEDS_BALANCE` | Non-tunai melebihi sisa tagihan |
| 422 | `DISCOUNT_OVER_LIMIT` | Diskon melebihi batas role tanpa approval |
| 423 | `PIN_LOCKED` | PIN dikunci 15 menit setelah 5 kali salah (`error.details.retry_after` detik) |
| 426 | `APP_UPDATE_REQUIRED` | Versi app di bawah `min_version` |
| 429 | `TOO_MANY_REQUESTS` | Rate limit terlampaui |
| 500 | `SERVER_ERROR` | Error tak terduga (detail hanya di log) |
| 503 | `MAINTENANCE` | Sistem sedang maintenance |

## Panel dashboard owner (`/dashboard`)

Panel Filament; owner hanya melihat data bisnisnya sendiri lewat `TenantContext` + `TenantScope`
(bukan fitur tenancy Filament — ADR 0007). Akses: owner & manager aktif.

| Menu | Fungsi | Role |
|---|---|---|
| Beranda | Widget omzet hari ini, jumlah transaksi, produk terlaris, komposisi metode bayar, produk stok ≤ 0 | O, M |
| Penjualan | Daftar order dengan filter tanggal/status/shift, detail, cetak ulang struk, void | O, M |
| Shift | Riwayat shift, selisih kas per kasir | O, M |
| Produk | Kategori, produk, grup opsi, import produk dari Excel | O, M |
| Stok | Penyesuaian stok dan daftar stok menipis | O, M |
| Laporan | Ringkasan, per produk, per metode bayar, export Excel | O, M |
| Karyawan | Tambah kasir/manager, atur PIN, nonaktifkan | O |
| Perangkat | Daftar device, cabut akses device hilang | O |
| Metode Pembayaran | Aktif/nonaktifkan metode, wajib approval code atau tidak | O |
| Pengaturan Outlet | Pajak, service charge, pembulatan, header/footer struk, logo | O |

## Panel maintenance (`/admin`)

Memakai tabel `admins` dan guard sendiri. Tidak pernah bisa diakses akun tenant.

| Menu | Fungsi |
|---|---|
| Tenant | Buat tenant + owner pertama, ubah status, atur `subscription_ends_at`, statistik pemakaian |
| Versi Aplikasi | Atur `min_version` dan `latest_version` Flutter |
| Pengumuman | Banner ke semua tenant |
| Maintenance Mode | `php artisan down` dengan secret bypass untuk admin |
| Backup | Daftar backup, backup manual, unduh file |
| Log Viewer | Membaca log Laravel tanpa SSH |
| Queue | Daftar job gagal, jalankan ulang |
| Admin | Kelola akun super admin |
| Keamanan | On/off wajib 2FA untuk semua super admin (konfirmasi kata sandi, ADR 0006); 2FA pribadi diatur di profil |

## Keamanan

| Area | Aturan |
|---|---|
| Isolasi tenant | Global scope `BelongsToTenant` + Policy per model; test wajib memastikan tenant A tidak bisa membaca data tenant B |
| Password & PIN | Di-hash (bcrypt/argon2); PIN dikunci 15 menit setelah 5 kali salah |
| Token | Sanctum per device dengan masa berlaku; owner bisa mencabut device |
| Rate limit | Login & PIN: 5 kali/menit per IP + device; API umum: 120 request/menit per token |
| Nominal transaksi | Selalu dihitung ulang di server |
| Void & diskon manual | Butuh permission atau PIN approver; tidak boleh approve transaksi sendiri |
| HTTPS | Wajib; redirect HTTP ke HTTPS |
| File sensitif | `.env`, `storage/`, `vendor/` di luar folder publik |
| Admin panel | Guard terpisah, 2FA aplikasi authenticator (wajib secara default, bisa diatur di menu Keamanan — ADR 0006) |

## Deploy ke cPanel

Project di luar `public_html`. Domain utama dan **ketiga subdomain** (`app.`, `admin.`, `api.`)
memakai document root yang sama: folder `public` (cPanel → Domains → Create/Manage → Document Root).
SSL (AutoSSL/Let's Encrypt) diaktifkan untuk keempatnya.

```
/home/{user}/
├── gs-point-of-sale-platform/      # Kode Laravel (hasil git clone)
│   └── public/                     # ← Document root gspos.id, app., admin., api.gspos.id
└── public_html/                    # Website lain, tidak disentuh
```

Langkah rilis:

1. Lokal: `npm run build` (aset Filament/Vite), commit, push.
2. cPanel Terminal: `git pull` di folder project.
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan optimize` dan `php artisan filament:optimize`
6. Cek `https://api.gspos.id/v1/system/status`, login `https://app.gspos.id`, dan `https://admin.gspos.id`.

Cron (satu saja): `* * * * * cd /home/{user}/gs-point-of-sale-platform && php artisan schedule:run >> /dev/null 2>&1`
(di beberapa hosting `php` diganti path lengkap, misal `/opt/cpanel/ea-php83/root/usr/bin/php`).

| Jadwal di `routes/console.php` | Frekuensi |
|---|---|
| `queue:work --stop-when-empty --max-time=50` | Setiap menit |
| `backup:run` | Harian 02:00 |
| `backup:clean` | Harian 03:00 |
| `sanctum:prune-expired` | Harian |

`.env` penting:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gspos.id
POS_APP_DOMAIN=app.gspos.id     # ADR 0008
POS_ADMIN_DOMAIN=admin.gspos.id
POS_API_DOMAIN=api.gspos.id
SESSION_DOMAIN=null         # cookie per subdomain: login app. tidak berlaku di admin.
MAIL_MAILER=smtp            # wajib: link verifikasi email daftar mandiri (ADR 0009)
MAIL_FROM_ADDRESS=no-reply@gspos.id
MAIL_FROM_NAME="gs.POS"
SESSION_SECURE_COOKIE=true
APP_TIMEZONE=UTC            # zona tampilan diambil dari outlets.timezone (ADR 0001)
DB_CONNECTION=mysql
DB_DATABASE={user}_gspos
DB_USERNAME={user}_gspos
DB_PASSWORD=...
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SENTRY_LARAVEL_DSN=...
```

## Testing & kualitas kode

| Jenis | Fokus | Tools |
|---|---|---|
| Unit test | `OrderCalculator` (diskon, service, pajak inclusive/exclusive, pembulatan), `OrderNumberGenerator` | Pest |
| Feature test API | Setiap endpoint: sukses, validasi gagal, role tidak berhak, isolasi tenant, checkout dikirim dua kali tetap satu order | Pest + SQLite in-memory; test yang memakai lock/JSON/constraint wajib MySQL (`--group=mysql`) |
| Format kode | Gaya konsisten | Laravel Pint |
| Static analysis | Error tipe dan pemanggilan salah | Larastan level 6+ |
| CI | Pint, Larastan, Pest di setiap push/PR | GitHub Actions |

Target minimal: semua Action dan endpoint transaksi punya test sebelum rilis ke klien pertama.

## Konvensi Git & rilis

Lihat [`docs/standards/workflow.md`](standards/workflow.md).

## Urutan pengerjaan (8 minggu, 1–2 developer)

1. **Minggu 1:** setup Laravel, Pint, Larastan, Pest, CI; migrasi tabel akses; trait `BelongsToTenant`; panel `/admin` dengan menu Tenant.
2. **Minggu 2:** Auth API (login, device, PIN), `/auth/me`, `/system/status`, middleware versi app dan tenant aktif.
3. **Minggu 3:** Katalog: kategori, produk, grup opsi via API dan panel `/dashboard`; endpoint `/catalog`.
4. **Minggu 4:** Shift, `OrderCalculator`, `/checkout`, nomor order, pengurangan stok.
5. **Minggu 5:** Open bill, tambah pembayaran, void, data struk.
6. **Minggu 6:** Laporan API + halaman laporan, widget beranda, export Excel.
7. **Minggu 7:** Menu admin lain (versi app, pengumuman, backup, log), keamanan, rate limit.
8. **Minggu 8:** Deploy ke cPanel, uji dengan aplikasi Flutter, perbaikan, pilot di klien pertama.

## Keputusan & pertanyaan terbuka

Keputusan atas pertanyaan yang muncul saat konversi PDF (disetujui 8 Okt 2026). Pertanyaan baru
ditambahkan ke tabel ini dengan status `Terbuka`.

| # | Topik | Keputusan | Status | Rujukan |
|---|---|---|---|---|
| Q1 | Zona waktu | Server & DB memakai UTC (`APP_TIMEZONE=UTC`). Zona tampilan dari kolom baru `outlets.timezone` (default `Asia/Jakarta`). | Diterima | ADR 0001 |
| Q2 | Nomor order | `YYMMDD` dan `order_sequences.date` memakai tanggal lokal outlet. | Diterima | ADR 0001 |
| Q3 | Error code | Tambah `PIN_LOCKED` (423). | Diterima | Daftar error code |
| Q4 | Error code | Tambah `DEVICE_NOT_REGISTERED` (403). | Diterima | Daftar error code |
| Q5 | Error code | Tambah `LAST_OWNER_REQUIRED` (409). | Diterima | Daftar error code |
| Q6 | Auth device | Device punya token sendiri (ability `device`) yang diterbitkan `POST /devices`; dipakai untuk `GET /devices/{uid}/cashiers` dan `POST /auth/pin-login`. | Diterima | ADR 0002 |
| Q7 | Status enum | Lihat tabel nilai enum di bagian Database. | Diterima | Database |
| Q8 | Testing | Test yang bergantung pada lock/JSON/constraint wajib jalan di MySQL (`--group=mysql`). | Diterima | `docs/standards/testing.md` |
| Q9 | Git | Pengerjaan saat ini memakai branch `feature/pos`; model `main`/`develop` ditunda. | Diterima | ADR 0003 |
| Q10 | `HasUuids` | Memakai trait bawaan Laravel (UUID v7); tidak dibuat di `Models/Concerns`. | Diterima | `docs/standards/project-structure.md` |
| Q11 | Versi PHP | Tetap PHP 8.2 → Laravel 12, Filament 5, Pest 3. | Diterima | ADR 0004 |
| Q12 | `is_available` | Kolom terpisah dari `is_active` untuk `PATCH /products/{id}/availability` (habis hari ini ≠ produk nonaktif). | Diterima | Database |
| Q13 | `options.tenant_id` | Ditambahkan agar semua model bisnis memakai `BelongsToTenant` (CLAUDE.md). | Diterima | Database |
| Q14 | Riwayat stok | Tabel `stock_movements`; `POST /products/{id}/stock` wajib `id` dari client sebagai idempotency key. | Diterima | `docs/api/product.md` |
| Q15 | Hapus kategori | Produknya tidak ikut dihapus; menjadi tanpa kategori (`category_id` null). | Diterima | `docs/api/product.md` |
| Q16 | Shift per device | `POST /shifts` saat device sudah punya shift terbuka mengembalikan shift itu (idempotent). Tutup biasa hanya oleh pembuka; selain itu tutup paksa. | Diterima | `docs/api/shift.md` |
| Q17 | Login email di device kasir | Token terikat device bila `device_uid` = device terdaftar, sehingga owner/manager bisa buka shift & checkout. | Diterima | `docs/api/auth.md` |
| Q18 | Batas diskon | Persen total diskon (item + order) terhadap harga kotor; role dengan `order.discount_over_limit` tidak dibatasi. | Diterima | `docs/api/order.md` |
| Q19 | Alokasi pembayaran | Non-tunai dialokasikan lebih dulu, tunai menutup sisa + kembalian; checkout wajib lunas. | Diterima | `docs/api/order.md` |
| Q20 | Open bill lintas shift | Open bill boleh tetap terbuka saat shift ditutup (ditampilkan di ringkasan); saat lunas, order pindah ke shift yang menerima uang. | Diterima | `docs/api/order.md` |
| Q21 | Visibilitas order | Kasir: order di shift sendiri + semua open bill. Supervisor (view_all tanpa report.view): hari ini. Owner/manager: semua. | Diterima | `docs/api/order.md` |
| Q22 | Approver void | Kolom `orders.void_approved_by` terpisah dari `approved_by` (approver diskon). | Diterima | Database |
| Q24 | Stok menipis | Batas per produk `products.min_stock` (nullable). Menipis = `track_stock` dan 0 < stok ≤ `min_stock`; stok ≤ 0 = habis. API: `min_stock`, `is_low_stock`. Dashboard: tab "Stok menipis" + warna kuning. | Diterima | `docs/api/product.md` |
| Q25 | Produk favorit | Tanda per produk untuk seluruh tenant (`products.is_favorite`), bukan per pengguna; aplikasi kasir menampilkan tab Favorit. Diubah dengan `product.manage`. | Diterima | `docs/api/product.md` |
| Q27 | Status kategori & grup opsi | `categories.is_active`, `option_groups.is_active` (default aktif). Kategori nonaktif tidak dikirim di katalog **beserta produknya**, dan checkout menolak produk tersebut ("Produk tidak tersedia"). Grup opsi nonaktif tidak dikirim dan dilepas dari `option_group_ids` produk di katalog; saat checkout aturannya (min/maks) tidak berlaku dan opsinya ditolak. Diubah dengan `product.manage`. | Diterima | `docs/api/product.md` |
| Q28 | URL & subdomain | `gspos.id` halaman depan, `app.gspos.id` panel pelanggan (tanpa `/dashboard`), `admin.gspos.id` panel super admin, `api.gspos.id/v1` API. Cookie session per subdomain. File publik `/storage` relatif ke host. | Diterima | ADR 0008 |
| Q29 | Halaman depan & login | `gspos.id`: halaman penjualan + form hubungi sales (honeypot, 3/menit & 10/jam per IP, IP disimpan sebagai HMAC) → menu Admin "Calon pelanggan". Login owner/manager di `gspos.id/login` → `app.gspos.id` lewat tiket sekali pakai 60 detik; 5 percobaan/menit per email+IP, pesan umum, waktu konstan. | Diterima | ADR 0008 |
| Q30 | Onboarding | Gabungan: daftar sendiri di `gspos.id/daftar` (bisa dimatikan dari admin) **dan** dibantu sales ("Buat tenant dari lead" + link atur kata sandi). Panduan setup + template menu di beranda `app.`. | Diterima | ADR 0009 |
| Q31 | Verifikasi email | Daftar sendiri langsung bisa dipakai; checkout & pembayaran ditolak `EMAIL_NOT_VERIFIED` sampai email owner diverifikasi (`users.email_verified_at`). | Diterima | ADR 0009 |
| Q32 | Trial & akses | Trial default 14 hari, diatur di admin (`system_settings.trial_days`). Habis → **hanya-baca** (`SUBSCRIPTION_EXPIRED`), bukan diblokir; `suspended` tetap diblokir (`TENANT_SUSPENDED`). | Diterima | ADR 0009 |
| Q33 | Edisi | `POS_EDITION=saas` (banyak tenant, halaman depan, trial) atau `self_hosted` (jual putus: satu tenant via `php artisan pos:install`, tanpa halaman depan/daftar, panel admin dipegang pembeli). Lisensi di luar MVP. | Diterima | ADR 0009 |
| Q34 | Kata sandi owner | Tidak pernah dikirim sebagai teks: owner dari sales menerima link atur kata sandi; lupa kata sandi di `gspos.id/lupa-sandi`. | Diterima | ADR 0009 |
| Q26 | Diskon produk | Belum ada promo/harga coret di level produk; diskon tetap lewat kasir (item & order, batas role + PIN, Q18). Promo produk masuk backlog. | Diterima | — |
| Q23 | Pengakuan omzet | Laporan memakai `completed_at` (order selesai); void dilaporkan terpisah berdasarkan `voided_at`; rentang tanggal lokal outlet, maks. 366 hari. | Diterima | `docs/api/report.md` |
