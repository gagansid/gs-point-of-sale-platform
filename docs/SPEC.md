# Spesifikasi gs-point-of-sale-platform

> Sumber: `Spesifikasi gs-point-of-sale-platform.pdf` (8 Okt 2026), dikonversi ke Markdown agar
> bisa di-review lewat PR. **File ini adalah sumber kebenaran**; PDF hanya arsip.
> Perubahan spesifikasi wajib lewat PR dengan label `spec` (lihat `docs/standards/documentation.md`).
> Pertanyaan yang belum terjawab dicatat di bagian [Keputusan & pertanyaan terbuka](#keputusan--pertanyaan-terbuka).

## Ringkasan

`gs-point-of-sale-platform` adalah satu aplikasi Laravel yang menjalankan seluruh sisi server POS:
REST API untuk aplikasi Flutter, dashboard web untuk owner, dan panel maintenance untuk super admin.
Semua bagian memakai model, aturan bisnis, dan database yang sama.

**Cakupan MVP:** multi-tenant dengan banyak outlet per bisnis (ADR 0010), kategori & produk dengan opsi tambahan,
kasir dengan split payment (cash, QRIS, transfer, debit, kredit dicatat manual), stok sederhana,
shift, void, dan laporan dasar.

**Fase 2:** mode darurat offline, resep. Sudah dimajukan: audit log (Q58), program member + voucher (ADR 0013,
Q60–Q67), meja & self-order QR + payment gateway Midtrans (ADR 0014, Q68–Q73).

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
| `barryvdh/laravel-dompdf` ^3.1 | Export laporan ke PDF (PHP murni, tanpa Chrome/biner — ADR 0012) | MVP |
| `opcodesio/log-viewer` | Membaca log Laravel dari panel admin | MVP |
| `spatie/laravel-activitylog` | Audit log perubahan data (Q58); void & diskon tetap di panel Audit | Terpasang |
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
| `order.discount_over_limit` | Diskon tanpa batas | ✅ | ✅ | 🔑 | 🔑 |
| `order.void` | Batalkan transaksi | ✅ | ✅ | ✅ | 🔑 |
| `order.refund` | Retur sebagian per item (ADR 0018) | ✅ | ✅ | ✅ | 🔑 |
| `shift.operate` | Buka/tutup shift di perangkat yang dipakai, kas masuk/keluar (ADR 0017) | ✅ | ✅ | ✅ | ✅ |
| `shift.view_all` | Semua shift & selisih kas | ✅ | ✅ | ✅ | ❌ |
| `shift.force_close` | Tutup paksa shift dari perangkat lain / dashboard | ✅ | ✅ | ✅ | ❌ |
| `shift.edit_opening_cash` | Kas awal berbeda dari sisa laci shift sebelumnya / koreksi kas awal (ADR 0017) | ✅ | ✅ | ✅ | 🔑 |
| `shift.cash_out_over_limit` | Kas keluar di atas batas outlet tanpa PIN (ADR 0017) | ✅ | ✅ | ✅ | 🔑 |
| `product.toggle_available` | Tandai menu habis/tersedia (Q115) | ✅ | ✅ | ✅ | ✅ |
| `product.manage` | Kategori, produk, opsi | ✅ | ✅ | ❌ | ❌ |
| `stock.adjust` | Penyesuaian stok | ✅ | ✅ | ❌ | ❌ |
| `stock.receive` | Stok masuk: buat, posting, batal (ADR 0015) | ✅ | ✅ | ✅ | ❌ |
| `stock.opname` | Stok opname: buat, isi hitungan, posting (ADR 0015) | ✅ | ✅ | ❌ | ❌ |
| `stock.transfer` | Transfer stok antar outlet: kirim (outlet asal), terima (outlet tujuan), batal (ADR 0015) | ✅ | ✅ | ❌ | ❌ |
| `supplier.manage` | Data supplier (ADR 0015) | ✅ | ✅ | ❌ | ❌ |
| `purchase.manage` | Purchase order ke supplier (ADR 0021) | ✅ | ✅ | ❌ | ❌ |
| `promo.manage` | Promo otomatis (ADR 0022) | ✅ | ✅ | ❌ | ❌ |
| `billing.manage` | Paket langganan & tagihan SaaS (ADR 0024) | ✅ | ❌ | ❌ | ❌ |
| `business.settings` | Profil bisnis & tampilan dashboard: nama, jenis usaha, logo, warna utama, sidebar, mode bawaan (Q128) | ✅ | ❌ | ❌ | ❌ |
| `report.view` | Semua laporan, semua periode (maks. 366 hari per query) | ✅ | ✅ | ❌ | ❌ |
| `report.view_shift` | Laporan operasional (Ringkasan, Produk, Metode bayar, Karyawan, Shift, Diskon & void), maks. 31 hari ke belakang (Q49) | ✅ | ✅ | ✅ | ❌ |
| `report.view_own` | Rekap shift sendiri: X-report (shift berjalan) & Z-report (saat tutup), Q49 | ✅ | ✅ | ✅ | ✅ |
| `report.export` | Export Excel & PDF untuk laporan yang boleh dilihat, batas periode sama dengan layar (Q52) | ✅ | ✅ | ✅ | ❌ |
| `user.manage` | Karyawan & PIN | ✅ | ❌ | ❌ | ❌ |
| `device.manage` | Perangkat | ✅ | ❌ | ❌ | ❌ |
| `payment_method.manage` | Metode bayar | ✅ | ❌ | ❌ | ❌ |
| `outlet.settings` | Pajak, service, pembulatan, struk | ✅ | ❌ | ❌ | ❌ |
| `outlet.manage` | Tambah & nonaktifkan outlet (Q36) | ✅ | ❌ | ❌ | ❌ |
| `outlet.access_all` | Otomatis memegang semua outlet (Q37) | ✅ | ❌ | ❌ | ❌ |
| `audit.view` | Riwayat perubahan data (Q58); jenis data mengikuti izin pengelolanya | ✅ | ✅ | ❌ | ❌ |
| `member.view` | Cari member & pasang ke transaksi (ADR 0013) | ✅ | ✅ | ✅ | ✅ |
| `member.create` | Daftar member baru (daftar cepat di kasir) | ✅ | ✅ | ✅ | ✅ |
| `member.manage` | Ubah, nonaktifkan, hapus (anonimkan) member | ✅ | ✅ | ❌ | ❌ |
| `member.points_adjust` | Tambah/kurangi poin manual dengan alasan | ✅ | ✅ | ❌ | ❌ |
| `member.settings` | Program member: aktif/nonaktif, outlet ikut, poin, diskon, tier (M5), kartu stempel (M6), voucher (M7) | ✅ | ❌ | ❌ | ❌ |
| `table.manage` | Meja & QR self-order, setelan self-order per outlet (ADR 0014) | ✅ | ✅ | ❌ | ❌ |
| `payment_gateway.manage` | Hubungkan akun Midtrans owner: key, mode, metode online (ADR 0014) | ✅ | ❌ | ❌ | ❌ |
| `self_order.handle` | Lihat, terima, & tolak pesanan dari meja (ADR 0014) | ✅ | ✅ | ✅ | ✅ |

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
            self::Supervisor => [
                'order.create', 'order.view_own', 'order.view_all', 'order.reprint',
                'order.discount', 'order.void', 'shift.*', 'product.toggle_available',
            ],
            self::Cashier => [
                'order.create', 'order.view_own', 'order.reprint',
                'order.discount', 'shift.operate', 'product.toggle_available',
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
(persen). Batas berjenjang (Q18): kasir & supervisor dibatasi batas role-nya; di atasnya wajib PIN approver
yang batasnya mencukupi (supervisor sampai batas supervisor, manager/owner tanpa batas). Setiap tenant wajib punya minimal 1 owner aktif.

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
| Akses | `tenants` | name, slug, business_type (cafe/retail/other), status (trial/active/suspended), subscription_ends_at, max_outlets (batas outlet aktif SaaS, null = tanpa batas, Q36) |
| Akses | `outlets` | tenant_id, code, name, address, tax_rate, service_charge_rate, tax_inclusive, rounding, receipt_header, receipt_footer, receipt_show_rounding (Q48), discount_limits (JSON), timezone (default `Asia/Jakarta`, ADR 0001), is_active (outlet dinonaktifkan, tidak dihapus — Q36) |
| Akses | `users` | tenant_id, name, email (unik global, wajib semua role — Q46), email_verified_at, username (unik per tenant, kasir/supervisor — Q35), password, web_cashier_access (Q54), phone (E.164, opsional — Q84), avatar_path (opsional — Q84), pin (hash), pin_failed_attempts, pin_locked_until, role, is_active, last_login_at |
| Akses | `outlet_user` | outlet_id, user_id — outlet yang dipegang karyawan (Q37); owner tidak perlu baris (otomatis semua outlet) |
| Akses | `devices` | tenant_id, outlet_id, name, device_uid (unik per tenant), platform, app_version, last_seen_at, revoked_at |
| Produk | `categories` | tenant_id, name, sort_order, is_active (Q27) |
| Produk | `products` | tenant_id, category_id (nullable), name, sku, barcode, price, cost_price, track_stock, image_path, is_active, is_favorite (favorit outlet, Q25), sort_order (urutan tampil kasir) |
| Produk | `outlet_product` | tenant_id, outlet_id, product_id (unik per outlet), stock_qty, min_stock (batas stok menipis, Q24), is_available (tanda habis, Q12), is_listed (dijual di outlet, Q42) — stok & ketersediaan per outlet (Q40) |
| Produk | `option_groups` | tenant_id, name (Ukuran, Gula, Topping), min_select, max_select, is_active (Q27) |
| Produk | `options` | tenant_id (Q13), option_group_id, name, price_delta, sort_order |
| Produk | `outlet_option` | tenant_id, outlet_id, option_id (unik per outlet), is_available — opsi habis per outlet (Q43) |
| Produk | `product_option_groups` | product_id, option_group_id, sort_order |
| Transaksi | `shifts` | tenant_id, outlet_id, device_id, opened_by, closed_by, opening_cash, expected_cash, actual_cash, difference, status, close_note, open_device_key (unik: 1 shift terbuka per device), opened_at, closed_at |
| Transaksi | `order_sequences` | outlet_id, date, last_number (penomoran order harian) |
| Transaksi | `orders` | tenant_id, outlet_id, shift_id, device_id, user_id, member_id, member_name, member_tier (snapshot, ADR 0013), channel (pos/self_order), dining_table_id, accepted_at (ADR 0014), points_earned, points_redeemed, member_discount, stamp_free_total, stamp_redemptions (json snapshot), stamps_earned, voucher_id, voucher_code (snapshot), voucher_discount, order_number, order_type, table_label, status (open/awaiting_payment/completed/voided/expired), notes, discount_type, discount_value, order_discount, subtotal, discount_total (item + order), service_total, tax_total, rounding, grand_total, paid_total, change_total, service_rate, tax_rate, tax_inclusive (setelan saat transaksi), void_reason, voided_by, void_approved_by, voided_at, refund_required (online lunas lalu void, Q73), approved_by (approver diskon), completed_at |
| Transaksi | `order_items` | order_id, product_id, product_name, category_name, unit_price, unit_cost, options_total, qty, discount, line_total, notes |
| Transaksi | `order_item_options` | order_item_id, option_id, option_name, price_delta |
| Pembayaran | `payment_methods` | tenant_id, name, category (cash/qris/transfer/debit/credit; sistem: points — ADR 0013, online — ADR 0014), requires_reference, is_active, sort_order — 5 metode bawaan dibuat untuk setiap tenant baru |
| Pembayaran | `outlet_payment_method` | tenant_id, outlet_id, payment_method_id (unik per outlet), is_active — metode bayar per outlet (Q44) |
| Produk | `stock_movements` | tenant_id, outlet_id, product_id, user_id, type (adjustment/sale/void_return; purchase/purchase_void/opname/transfer_out/transfer_in/transfer_cancel — ADR 0015), qty_change, qty_after, reason, reference_id (order / dokumen stok) — riwayat stok (Q14) |
| Stok | `suppliers` | tenant_id, name (unik per bisnis), phone, email, address, notes, is_active (ADR 0015) |
| Stok | `stock_receipts` | tenant_id, outlet_id, number (`{outlet}-SM-{YYMMDD}-{urut}`), supplier_id, received_on (tanggal lokal), invoice_number, notes, status (draft/posted/cancelled), total_cost, posted_at, posted_by, cancelled_at, cancelled_by, cancel_reason — `id` dari klien = idempotency key (Q79) |
| Stok | `stock_receipt_items` | tenant_id, stock_receipt_id, product_id, product_name (snapshot), qty (> 0), unit_cost, line_total, cost_before, cost_after (modal rata-rata tertimbang saat posting, Q80), sort_order |
| Stok | `stock_opnames` | tenant_id, outlet_id, number (`{outlet}-SO-{YYMMDD}-{urut}`), category_id (lingkup, null = semua), counted_on, notes, status (draft/posted), difference_qty, difference_value, posted_at, posted_by (Q81) |
| Stok | `stock_opname_items` | tenant_id, stock_opname_id, product_id (unik per lembar), product_name, system_qty (saat dibuat), counted_qty (null = tidak dihitung), stock_at_post, difference, unit_cost, difference_value |
| Stok | `stock_transfers` | tenant_id, number (`{outlet asal}-TR-{YYMMDD}-{urut}`), from_outlet_id, to_outlet_id, status (draft/in_transit/received/cancelled), notes, sent_at/by, received_at/by, cancelled_at/by, cancel_reason (Q82) |
| Stok | `stock_transfer_items` | tenant_id, stock_transfer_id, product_id (digabung per produk), product_name, qty_sent, qty_received (null = belum diterima) |
| Stok | `document_sequences` | outlet_id, type (SM/SO/TR), date (lokal outlet), last_number — nomor dokumen stok, baris dikunci |
| Member | `members` | tenant_id, code (6 karakter acak, unik per tenant), name, phone (+62…, unik per tenant), email, birth_day, birth_month, notes, password & pin (M3–M4), tier_id, points_balance, spend_12m, total_spent, visit_count, last_visit_at, joined_outlet_id, consent_at, is_active, anonymized_at (ADR 0013) |
| Member | `member_settings` | tenant_id (unik), enabled, earn_amount, earn_points, redeem_value, min_redeem_points, max_redeem_percent, expiry_months, member_discount_percent (Q62–Q63), birthday_voucher (json: enabled, type, value, max_discount, min_spend — Q66) |
| Member | `member_point_ledger` | tenant_id, member_id, outlet_id, order_id, payment_id, type (earn/redeem/void_reversal/adjust/expire), points (+/−), remaining (FIFO), balance_after, expires_at, reason, user_id — append-only (Q62) |
| Member | `outlet_member_program` | tenant_id, outlet_id (unik), is_enabled — tanpa baris = outlet ikut program |
| Member | `member_tiers` | tenant_id, name, min_spend_12m (batas belanja 12 bulan bergulir), point_multiplier, discount_percent (ditambahkan ke diskon member) — maks. 10 per bisnis (Q64) |
| Member | `stamp_programs` + `stamp_program_items` | tenant_id, name, stamps_required (2–100), is_active; item: stamp_program_id, product_id **atau** category_id (Q65) |
| Member | `member_stamps` | tenant_id, member_id, stamp_program_id, outlet_id, order_id, type (earn/redeem/void_reversal/adjust), change (+/−), balance_after, reason, user_id — append-only, saldo = Σ change (Q65) |
| Member | `vouchers` | tenant_id, code (huruf besar, unik per tenant; null = voucher pribadi), name, source (owner/birthday), type (fixed/percent), value, max_discount, min_spend, quota, per_member_quota, used_count, starts_at, ends_at, member_only, tier_ids, outlet_ids (json), member_id (voucher pribadi), is_active (Q66) |
| Member | `voucher_redemptions` | tenant_id, voucher_id, member_id, order_id (unik per voucher), outlet_id, amount, status (used/voided) (Q66) |
| Self-order | `dining_tables` | tenant_id, outlet_id, name (unik per outlet), area, qr_token (acak 32, unik), short_code (6 karakter, unik global — Q85), is_active, sort_order — nama `dining_tables` karena `tables` kata kunci MySQL (ADR 0014, Q69) |
| Self-order | `self_order_settings` | tenant_id, outlet_id (unik), enabled, payment_timeout_minutes (5–120, bawaan 15), opens_at, closes_at (lokal outlet), allow_pay_at_cashier, welcome_text |
| Self-order | `self_orders` | tenant_id, outlet_id, dining_table_id, member_id, customer_name, items (json: product_id, qty, option_ids, notes), notes, voucher_code, estimated_total, status (pending/accepted/rejected/expired), reject_reason, order_id, handled_by, handled_at, ip_address — `id` dari HP = idempotency key (Q76) |
| Pembayaran | `payment_gateway_settings` | tenant_id (unik), provider (midtrans), enabled, is_production, server_key & client_key (terenkripsi, tidak pernah ditampilkan), enabled_payments (json), verified_at (Q72) |
| Pembayaran | `gateway_transactions` | id (= order_id Midtrans), tenant_id, order_id, amount (rupiah bulat), status (pending/settlement/expire/cancel/deny/failure/refund_required), snap_token, redirect_url, transaction_id, payment_type, payment_id, settled_at (Q72) |
| Pembayaran | `payments` | tenant_id, order_id, payment_method_id, user_id, category, amount (dipakai membayar), tendered (uang diterima), change, reference (approval code), status |
| Sistem Laravel | `personal_access_tokens`, `sessions`, `cache`, `jobs`, `failed_jobs` | Bawaan Laravel / Sanctum |

Nilai enum status (keputusan Q7):

| Kolom | Nilai |
|---|---|
| `orders.status` | `open` (open bill), `awaiting_payment`, `completed`, `voided`, `expired` (ADR 0014), `merged` (digabung, ADR 0019) |
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
3. Dasar = subtotal − gratis stempel − promo otomatis (ADR 0022) − diskon order − voucher − diskon member/tier (ADR 0013, Q63)
4. Service charge = dasar × tarif service outlet
5. Pajak = (dasar + service charge) × tarif pajak outlet (atau diekstrak dari harga jika `tax_inclusive`)
6. Pembulatan ke atas sesuai setelan outlet (misal ke kelipatan Rp100 berikutnya; tidak pernah minus)
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
- Shift milik **perangkat**, dipakai bergantian (ADR 0017): ganti kasir = kunci layar → PIN karyawan lain, tanpa hitung
  uang; operator tercatat di log shift. Tutup biasa oleh siapa saja yang bertugas di perangkat itu.
- Tutup shift: `expected_cash` = kas awal + penjualan cash − kembalian + kas masuk − kas keluar, dibandingkan dengan
  `actual_cash`. Isian Disetor → `closing_float` = aktual − disetor = kas awal shift berikutnya di perangkat yang sama.
- Void hanya untuk order di shift yang masih terbuka, wajib alasan, kasir butuh PIN owner/manager/supervisor.
  Order yang sudah diretur sebagian tidak bisa di-void.
- Retur sebagian (ADR 0018): order selesai ≤ `outlets.refund_max_days` hari (bawaan 30, 0 = tidak boleh), per item & qty, nilai proporsional grand total, uang tunai dari
  laci shift berjalan di perangkat pelaku (atau manual), stok kembali bila dipilih.

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
| GET | `/shifts/current` | Semua | Shift terbuka di device ini (+ `meta.carry_over_cash` bila belum ada) |
| POST | `/shifts` | Semua | Buka shift; kas awal kosong = sisa laci shift sebelumnya (ADR 0017) |
| POST | `/shifts/{id}/close` | Semua | Tutup shift di device ini + kas aktual & disetor, kembalikan ringkasan |
| GET | `/shifts/{id}/summary` | Semua | Ringkasan penjualan per metode bayar, kas masuk/keluar |
| POST | `/shifts/{id}/force-close` | O, M, S | Tutup paksa shift di device lain (lupa ditutup / device rusak) |
| POST | `/shifts/{id}/opening-cash` | Semua 🔑 | Koreksi kas awal shift berjalan (ADR 0017) |
| GET | `/shifts/{id}/cash-movements` | Semua | Kas masuk/keluar shift |
| POST | `/cash-movements` | Semua 🔑 | Catat kas masuk/keluar di shift device ini (ADR 0017) |

### Order & pembayaran

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| POST | `/checkout` | Semua | Buat order + bayar sekaligus (takeaway/retail) |
| POST | `/orders/quote` | Semua | Pratinjau total dari server, termasuk promo otomatis (ADR 0022) |
| PUT | `/orders/{id}` | Semua | Simpan / ubah open bill (dine-in) |
| POST | `/orders/{id}/payments` | Semua | Tambah pembayaran ke open bill |
| POST | `/orders/{id}/refunds` | Semua 🔑 | Retur sebagian per item (ADR 0018) |
| POST | `/orders/{id}/split` | Semua | Pecah open bill ke bill baru (ADR 0019) |
| POST | `/orders/{id}/merge` | Semua | Gabung open bill lain ke bill ini (ADR 0019) |
| GET | `/refundable-orders/{nomor}` | Semua | Cari transaksi selesai untuk diretur (outlet device, batas hari) |
| GET | `/members/{id}/receivables` | Semua | Riwayat kasbon member (ADR 0023) |
| POST | `/members/{id}/receivable-payments` | Semua | Pelunasan kasbon (ADR 0023) |
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

### Member (ADR 0013)

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/members?search=` | Semua | Cari member (HP/kode/nama), HP disamarkan, 30/menit |
| POST | `/members` | Semua | Daftar cepat (persetujuan wajib, HP unik per bisnis) |
| GET | `/members/{id}` | Semua | Detail member |
| GET | `/members/{id}/points` | Semua | Saldo & riwayat poin |
| GET | `/vouchers/check?code=` | Semua | Cek kode promo untuk outlet perangkat (+ `member_id`, `subtotal` opsional), 30/menit |
| GET | `/self-orders` | Semua | Pesanan masuk dari QR meja (polling), `meta.pending_count` & `unpaid_count` (ADR 0014) |
| POST | `/self-orders/{id}/accept` | Semua | Terima → order `awaiting_payment` di shift perangkat |
| POST | `/self-orders/{id}/reject` | Semua | Tolak dengan alasan |

`/checkout` & `PUT /orders/{id}` menerima `member_id`, `stamp_program_ids`, `voucher_code` / `voucher_id` (lihat `docs/api/member.md`).

### Laporan & setelan

| Method | Endpoint | Role | Fungsi |
|---|---|---|---|
| GET | `/reports/summary?from=&to=` | O, M | Omzet, jumlah transaksi, rata-rata, diskon, pajak |
| GET | `/reports/products?from=&to=` | O, M | Penjualan per produk |
| GET | `/reports/payment-methods?from=&to=` | O, M | Penjualan per metode bayar |
| GET | `/reports/dashboard?from=&to=&compare=` | O, M | Ringkasan beranda: KPI + periode pembanding (`previous_period` default, `previous_year`, `none`) + persen perubahan (null bila pembanding 0), tren per jam (1 hari) / per hari, jam ramai, metode bayar, top 10 produk, tipe order, perbandingan outlet (hanya semua outlet) |
| GET | `/reports/hourly?from=&to=` | O, M | Omzet & transaksi per jam lokal outlet (0–23) |
| GET | `/reports/audit?from=&to=` | O, M | Void (pelaku, approver, alasan), diskon dengan approval PIN, shift dengan selisih kas / ditutup paksa (20 terbaru + jumlah total) |
| GET | `/outlets` | Semua | Outlet yang dipegang user (Q37) |
| GET, PUT | `/outlet` | O | Pajak, service charge, pembulatan, header/footer struk, laci kasir (outlet perangkat) |
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
| 403 | `FEATURE_NOT_AVAILABLE` | Fitur tidak termasuk paket langganan (atau add-on) bisnis; `error.details.feature` = key fitur (ADR 0025) |
| 404 | `NOT_FOUND` | Data tidak ditemukan / milik tenant lain |
| 404 | `TABLE_NOT_FOUND` | QR meja tidak valid, sudah diganti, atau meja nonaktif (ADR 0014) |
| 404 | `MEMBER_NOT_FOUND` | Member tidak ada, nonaktif, dihapus, atau milik tenant lain (ADR 0013) |
| 409 | `SHIFT_NOT_OPEN` | Transaksi tanpa shift terbuka |
| 409 | `ORDER_ALREADY_CLOSED` | Mengubah order yang sudah selesai/void |
| 409 | `LAST_OWNER_REQUIRED` | Menonaktifkan/menurunkan role owner aktif terakhir |
| 409 | `ORDER_NOT_PENDING` | Pesanan meja sudah diterima/ditolak kasir lain (ADR 0014) |
| 409 | `ORDER_EXPIRED` | Pesanan meja melewati batas waktu (belum diterima atau belum dibayar) (ADR 0014) |
| 409 | `OUTLET_LIMIT_REACHED` | Menambah/mengaktifkan outlet melebihi kuota `outlets` (Q36, ADR 0025); `error.details`: `max_outlets`, `quota`, `limit`, `used` |
| 409 | `PLAN_LIMIT_REACHED` | Menambah/mengaktifkan karyawan, perangkat, produk, atau member melebihi kuota paket; `error.details`: `quota`, `limit`, `used` (ADR 0025) |
| 422 | `VALIDATION_ERROR` | Input tidak valid (detail per field di `error.details`) |
| 422 | `MEMBER_PROGRAM_DISABLED` | Program member nonaktif di bisnis atau outlet dikecualikan (ADR 0013) |
| 422 | `INSUFFICIENT_POINTS` | Poin ditukar melebihi saldo, di bawah minimal, atau di atas maksimal persen tagihan (ADR 0013) |
| 422 | `INVALID_MEMBER_PROOF` | Penukaran poin tanpa/dengan PIN member atau kode QR yang salah/kedaluwarsa |
| 422 | `PAYMENT_GATEWAY_NOT_CONFIGURED` | Bayar online dipilih tetapi Midtrans owner belum aktif/lengkap (ADR 0014) |
| 422 | `SELF_ORDER_DISABLED` | Self-order nonaktif di outlet, di luar jam layanan, atau bisnis hanya-baca (ADR 0014) |
| 422 | `VOUCHER_INVALID` | Voucher tidak bisa dipakai; `error.details.reason`: not_found, inactive, not_started, expired, quota_exhausted, member_quota_exhausted, min_spend, member_only, tier, outlet, not_owner (ADR 0013, Q66) |
| 422 | `PAYMENT_EXCEEDS_BALANCE` | Non-tunai melebihi sisa tagihan |
| 422 | `DISCOUNT_OVER_LIMIT` | Diskon melebihi batas role tanpa approval |
| 423 | `PIN_LOCKED` | PIN dikunci 15 menit: 5 kali salah dari satu sumber (login / approval, per perangkat) atau 15 kali salah dari semua perangkat (`error.details.retry_after` detik) |
| 423 | `MEMBER_PIN_LOCKED` | PIN member dikunci 15 menit setelah 5 kali salah (`error.details.retry_after` detik) |
| 426 | `APP_UPDATE_REQUIRED` | Versi app di bawah `min_version` |
| 429 | `TOO_MANY_REQUESTS` | Rate limit terlampaui |
| 500 | `SERVER_ERROR` | Error tak terduga (detail hanya di log) |
| 502 | `PAYMENT_GATEWAY_ERROR` | Midtrans tidak bisa dihubungi atau menolak permintaan (ADR 0014) |
| 503 | `MAINTENANCE` | Sistem sedang maintenance |

## Panel dashboard owner (`/dashboard`)

Panel Filament; owner hanya melihat data bisnisnya sendiri lewat `TenantContext` + `TenantScope`
(bukan fitur tenancy Filament — ADR 0007). Akses: owner & manager aktif; supervisor hanya menu Laporan (Q50).

| Grup | Menu | Fungsi | Role |
|---|---|---|---|
| — | Beranda | Ringkasan bisnis berfilter live (outlet = pemilih sidebar, periode preset/kustom, pembanding, tipe order, metode bayar, kasir): KPI + tren, grafik omzet vs pembanding, metode bayar, jam ramai, produk terlaris/kurang laku, tipe order, riwayat pendapatan harian, perbandingan outlet, panel audit (void, diskon ber-approval, selisih kas shift), stok ≤ 0, export Excel | O, M |
| — | Laporan | 15 tab (Ringkasan, Produk, Kategori, Laba kotor, Opsi, Metode bayar, Karyawan, Shift, Waktu, Tipe order, Outlet, Diskon & void, Stok, **Pembelian**, Member), filter live, export Excel & PDF (Q49–Q52) | O, M; S: tab operasional ≤ 31 hari (Q50) |
| Topbar | Lonceng peringatan | Hitungan langsung (tanpa tabel notifikasi/job): pesanan meja menunggu, produk habis/menipis di outlet aktif, shift terbuka > 24 jam, kasbon lama ≥ 30 hari; tiap jenis hanya bila menu tujuannya boleh dibuka (Q130) | O, M |
| Transaksi | Kasir | Tab baru ke kasir web (Q55) | O, M (`web_cashier`) |
| Transaksi | Penjualan | Daftar order: tab Semua · Selesai · Open bill · Menunggu bayar · Void · **Diretur**, filter tanggal/tipe/kanal/kasir, detail, cetak ulang struk, void, riwayat retur | O, M |
| Transaksi | Pesanan meja | Riwayat pesanan QR meja (menunggu/diterima/ditolak/kedaluwarsa), alasan tolak, tautan ke order; hanya baca (Q130) | O, M (`self_order`) |
| Transaksi | Shift | Riwayat shift, selisih kas per kasir, kas masuk/keluar, tutup paksa | O, M |
| Katalog | Produk · Kategori · Grup opsi | Produk (import Excel, sesuaikan stok, **riwayat stok**, stok menipis), kategori, grup opsi | O, M |
| Stok | Purchase order · Stok masuk · Transfer · Stok opname · **Riwayat stok** · Supplier | Dokumen stok (ADR 0015, 0021); Riwayat stok = kartu stok `stock_movements` per produk & outlet dengan stok akhir dan dokumen asal, hanya baca (Q14, Q130) | O, M (`stock`) |
| Pelanggan | Member · **Kasbon** · Program member · Kartu stempel · Voucher · Promo | Member & program loyalitas (ADR 0013), kasbon semua member: saldo, batas, umur sejak saldo naik dari nol, pelunasan (ADR 0023, Q130), promo (ADR 0022) | O, M |
| Pengaturan | Bisnis & tampilan · Outlet · Karyawan · Perangkat · Metode pembayaran · Pembayaran online · Meja & QR | Setelan toko per izin & fitur paket | O (sebagian M) |
| Akun | Langganan · Riwayat perubahan | Langganan gs.POS, riwayat perubahan data (audit) | O; riwayat juga M (katalog) |

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
| Password & PIN | Di-hash (bcrypt/argon2); PIN dikunci 15 menit setelah 5 kali salah per sumber (login atau approval, per perangkat), dikunci di semua perangkat setelah 15 kali salah dalam 15 menit; satu perangkat maksimal 20 PIN salah per 15 menit (`TOO_MANY_REQUESTS`). Owner bisa membuka kunci (GSPOS-SEC-002) |
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

Instalasi pertama lewat form web `/install` (paket zip, tanpa terminal): [`docs/install.md`](install.md).
Langkah rilis otomatis (PR di-approve → merge ke `master` → CI → deploy SSH/rsync): lihat [`docs/deploy.md`](deploy.md).
Langkah manual (bila tanpa GitHub Actions):

1. Lokal: `npm run build` (aset Filament/Vite), commit, push.
2. cPanel Terminal: `git pull` di folder project.
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan optimize` dan `php artisan filament:optimize`
6. Cek `https://api.gspos.id/v1/system/status`, login `https://app.gspos.id`, dan `https://admin.gspos.id`.

**Edisi jual putus** (`POS_EDITION=self_hosted`, ADR 0009): langkah sama, lalu sekali saja
`php artisan pos:install` (nama bisnis, owner, kata sandi — membuat satu bisnis, outlet, owner
terverifikasi, metode bayar bawaan; tanpa trial) dan `php artisan pos:create-admin` (akun maintenance
panel admin, 2FA). Halaman depan, daftar mandiri, hubungi sales, menu Calon pelanggan & Pendaftaran
otomatis nonaktif; subdomain opsional (boleh satu domain).

Cron (satu saja): `* * * * * cd /home/{user}/gs-point-of-sale-platform && php artisan schedule:run >> /dev/null 2>&1`
(di beberapa hosting `php` diganti path lengkap, misal `/opt/cpanel/ea-php83/root/usr/bin/php`).

| Jadwal di `routes/console.php` | Frekuensi |
|---|---|
| `queue:work --stop-when-empty --max-time=50` | Setiap menit |
| `backup:run` (database + `storage/app/public`, disk privat `backups`) | Harian 02:00 WIB |
| `backup:clean` (maks. `BACKUP_MAX_MB`) | Harian 03:00 WIB |
| `backup:monitor` (email bila backup terlalu lama/besar) | Harian 04:00 WIB |
| `sanctum:prune-expired` | Harian |
| `auth:clear-resets` | Harian |

Folder `storage/` (termasuk `storage/fonts`, cache font PDF dompdf) wajib bisa ditulis PHP. Backup MySQL butuh `mysqldump`; bila tidak ada di PATH hosting, isi `dump.dump_binary_path` koneksi `mysql` di `config/database.php`. Isi `BACKUP_NOTIFY_EMAIL` dan `BACKUP_ARCHIVE_PASSWORD` (zip terenkripsi) di `.env` production.

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
| Q16 | Shift per device | `POST /shifts` saat device sudah punya shift terbuka mengembalikan shift itu (idempotent). Revisi ADR 0017 (Q87): tutup biasa oleh siapa saja yang bertugas di device shift itu; tutup paksa untuk menutup dari device lain/dashboard. | Diterima | `docs/api/shift.md` |
| Q17 | Login email di device kasir | Token terikat device bila `device_uid` = device terdaftar, sehingga owner/manager bisa buka shift & checkout. | Diterima | `docs/api/auth.md` |
| Q18 | Batas diskon | Persen total diskon (item + order) terhadap harga kotor. Hanya role dengan `order.discount_over_limit` (owner, manager) yang tidak dibatasi; kasir & supervisor sampai `discount_limits[role]`. Di atas batas → PIN approver yang batasnya ≥ persen diskon (mis. kasir 20% → supervisor; 50% → manager/owner). Revisi 2026-10-11 (GSPOS-SEC-014): sebelumnya supervisor tanpa batas sehingga `discount_limits.supervisor` tidak berlaku. | Diterima | `docs/api/order.md` |
| Q19 | Alokasi pembayaran | Non-tunai dialokasikan lebih dulu, tunai menutup sisa + kembalian; checkout wajib lunas. | Diterima | `docs/api/order.md` |
| Q20 | Open bill lintas shift | Open bill boleh tetap terbuka saat shift ditutup (ditampilkan di ringkasan); saat lunas, order pindah ke shift yang menerima uang. | Diterima | `docs/api/order.md` |
| Q21 | Visibilitas order | Kasir: order di shift yang ia buka + shift yang sedang terbuka di perangkatnya (ADR 0017) + semua open bill. Supervisor (view_all tanpa report.view): hari ini. Owner/manager: semua. | Diterima | `docs/api/order.md` |
| Q22 | Approver void | Kolom `orders.void_approved_by` terpisah dari `approved_by` (approver diskon). | Diterima | Database |
| Q24 | Stok menipis | Batas per produk `products.min_stock` (nullable). Menipis = `track_stock` dan 0 < stok ≤ `min_stock`; stok ≤ 0 = habis. API: `min_stock`, `is_low_stock`. Dashboard: tab "Stok menipis" + warna kuning. | Diterima | `docs/api/product.md` |
| Q25 | Produk favorit | Tanda per produk untuk seluruh tenant (`products.is_favorite`), bukan per pengguna; aplikasi kasir menampilkan tab Favorit. Diubah dengan `product.manage`. | Diterima | `docs/api/product.md` |
| Q27 | Status kategori & grup opsi | `categories.is_active`, `option_groups.is_active` (default aktif). Kategori nonaktif tidak dikirim di katalog **beserta produknya**, dan checkout menolak produk tersebut ("Produk tidak tersedia"). Grup opsi nonaktif tidak dikirim dan dilepas dari `option_group_ids` produk di katalog; saat checkout aturannya (min/maks) tidak berlaku dan opsinya ditolak. Diubah dengan `product.manage`. | Diterima | `docs/api/product.md` |
| Q28 | URL & subdomain | `gspos.id` halaman depan, `app.gspos.id` panel pelanggan (tanpa `/dashboard`), `admin.gspos.id` panel super admin, `api.gspos.id/v1` API. Cookie session per subdomain. File publik `/storage` relatif ke host. | Diterima | ADR 0008 |
| Q29 | Halaman depan & login | `gspos.id`: halaman penjualan + form hubungi sales (honeypot, 3/menit & 10/jam per IP, IP disimpan sebagai HMAC) → menu Admin "Calon pelanggan". Login owner/manager di `gspos.id/login` → `app.gspos.id` lewat tiket sekali pakai 60 detik; 5 percobaan/menit per email+IP, pesan umum, waktu konstan. | Diterima | ADR 0008 |
| Q30 | Onboarding | Gabungan: daftar sendiri di `gspos.id/register` (bisa dimatikan dari admin) **dan** dibantu sales ("Buat tenant dari lead" + link atur kata sandi). Panduan setup + template menu di beranda `app.`. | Diterima | ADR 0009 |
| Q31 | Verifikasi email | Daftar sendiri langsung bisa dipakai; checkout & pembayaran ditolak `EMAIL_NOT_VERIFIED` sampai email owner diverifikasi (`users.email_verified_at`). | Diterima | ADR 0009 |
| Q32 | Trial & akses | Trial default 14 hari, diatur di admin (`system_settings.trial_days`). Habis → **hanya-baca** (`SUBSCRIPTION_EXPIRED`), bukan diblokir; `suspended` tetap diblokir (`TENANT_SUSPENDED`). | Diterima | ADR 0009 |
| Q33 | Edisi | `POS_EDITION=saas` (banyak tenant, halaman depan, trial) atau `self_hosted` (jual putus: satu tenant via `php artisan pos:install`, tanpa halaman depan/register, panel admin dipegang pembeli). Lisensi di luar MVP. | Diterima | ADR 0009 |
| Q34 | Kata sandi owner | Tidak pernah dikirim sebagai teks: owner dari sales menerima link atur kata sandi; lupa kata sandi di `gspos.id/forgot-password` (link 60 menit); undangan owner dari tim/sales berlaku 3 hari di tabel token terpisah (`user_invitation_tokens`). Membuka link = email terverifikasi; semua token lama diputus. | Diterima | ADR 0009 |
| Q35 | Kasir web | Fase berikutnya ada kasir di web sebagai cadangan bila tablet rusak. Supervisor/kasir wajib **username** (unik per tenant, huruf kecil/angka/`._-`) + **kata sandi** selain PIN; login kasir web = kode bisnis (slug tenant) + username + kata sandi. Owner/manager tetap email + kata sandi. | Diterima | `docs/api/settings.md` |
| Q36 | Multi-outlet | Dimajukan dari Fase 2: owner menambah outlet (Pengaturan → Outlet), batas `tenants.max_outlets` diatur admin (SaaS). Outlet dinonaktifkan, tidak dihapus. | Diterima | ADR 0010 |
| Q37 | Penugasan outlet | `outlet_user` banyak-ke-banyak menggantikan `users.outlet_id`. Owner otomatis semua outlet; manager, supervisor, kasir boleh lebih dari satu. | Diterima | ADR 0010 |
| Q38 | Akses per outlet | Order, shift, laporan, perangkat, stok hanya untuk outlet yang ditugaskan; outlet lain → `404 NOT_FOUND` (fail-closed). | Diterima | ADR 0010 |
| Q39 | Katalog & harga | ~~Katalog dan harga sama untuk semua outlet.~~ Diganti Q42: katalog bersama, produk dipilih per outlet; harga tetap sama (harga per outlet di backlog). | Diganti | ADR 0010, ADR 0011 |
| Q40 | Stok per outlet | `outlet_product`: `is_available`, `stock_qty`, `min_stock` per outlet; "lacak stok" tetap setting produk. Checkout memotong stok outlet device. | Diterima | ADR 0010 |
| Q41 | Pemilih outlet dashboard | Di atas menu sidebar, di bawah logo (seperti workspace; mode ciut = ikon saja): "Semua outlet" atau satu outlet, disimpan di session; laporan/penjualan/shift mengikuti. Awalnya di topbar, dipindah agar topbar bersih. | Diterima | ADR 0010 |
| Q42 | Menu per outlet | `outlet_product.is_listed` (default true): produk dijual di outlet. Tidak dijual → tidak dikirim di `/catalog`, checkout `VALIDATION_ERROR` per item. Kategori tampil bila ada produk yang dijual di outlet. | Diterima | ADR 0011 |
| Q43 | Opsi habis per outlet | `outlet_option.is_available` (tanpa baris = tersedia). Opsi habis dikirim dengan `is_available=false`; checkout `VALIDATION_ERROR` per item. | Diterima | ADR 0011 |
| Q44 | Metode bayar per outlet | `outlet_payment_method.is_active`; metode dipakai bila aktif di bisnis dan di outlet device. Nama tetap satu per bisnis. Nonaktif → `VALIDATION_ERROR` per pembayaran. | Diterima | ADR 0011 |
| Q45 | Outlet baru | Opsi salin menu & metode bayar dari outlet lain (default outlet pertama); tanpa salin = semua produk dijual, semua metode aktif. Tanpa baris per outlet = dijual/tersedia/aktif, jadi data lama tidak perlu disalin. | Diterima | ADR 0011 |
| Q46 | Email karyawan | Email **wajib untuk semua role** (unik global). Email baru/diganti = belum terverifikasi; link verifikasi (3 hari, tanpa login) dikirim ke email itu; kirim ulang dari Karyawan maks. 3×/10 menit. Supervisor (dashboard tanpa `report.view`) tidak bisa login dashboard sebelum email terverifikasi; role lain belum diblokir. | Diterima | `docs/api/settings.md` |
| Q47 | Audit pembuat & pengubah | Kolom `created_by`, `updated_by` (uuid, tanpa FK) di categories, products, option_groups, payment_methods, outlets, users, orders, shifts (→ users) dan tenants, sales_leads (→ admins). Ditampilkan di detail ("oleh {nama}") dan kolom tabel tersembunyi. | Diterima | `docs/standards/ui/components/audit-info.md` |
| Q48 | Pembulatan di struk | Bawaan pembulatan **digabung ke Total** (tanpa baris). Setelan outlet `receipt_show_rounding` (Profil outlet → Struk; API `PUT /outlet`) menampilkan barisnya. Hanya tampilan struk; perhitungan & data order tidak berubah; detail order di dashboard tetap menampilkan pembulatan (audit). | Diterima | `docs/api/settings.md` |
| Q49 | Akses laporan per role | `report.view` (owner, manager): semua tab & periode. `report.view_shift` (+ supervisor): tab operasional, outlet yang dipegang, **maks. 31 hari ke belakang** (`ReportPeriod::SUPERVISOR_LOOKBACK_DAYS`), dipotong di server; API di luar batas → `422 VALIDATION_ERROR`. `report.view_own` (+ kasir): rekap shift sendiri (X/Z-report). Cakupan selalu diterapkan di query, bukan hanya UI. | Diterima | `docs/api/report.md` |
| Q50 | Supervisor di panel `/dashboard` | Supervisor boleh masuk `/dashboard` **hanya untuk menu Laporan** (tab `report.view_shift`); menu lain disembunyikan lewat permission. Kasir tidak masuk panel: rekap shift sendiri lewat API Flutter (+ PDF) dan nanti kasir web (Q35). Akses panel ditentukan dari permission, bukan role. | Diterima | ADR 0007 |
| Q51 | Snapshot kategori | `order_items.category_name` disimpan saat checkout (seperti nama & harga). Transaksi lama diisi dari kategori produk saat migrasi; produk tanpa kategori = "Tanpa kategori". Laporan Kategori memakai snapshot ini. | Diterima | `docs/api/report.md` |
| Q52 | Export laporan | Excel & PDF **sinkron** (tanpa queue, shared hosting): data teragregasi SQL, maks. 366 hari, tabel detail memakai cursor/chunk; terlalu berat → ditolak dengan pesan "Persempit rentang tanggal", tidak dipotong diam-diam. Maks. 10 export/menit per user (semua jalur: Excel, PDF, PDF shift API → `TOO_MANY_REQUESTS`). PDF via dompdf (ADR 0012). Nama file `laporan-{tab|lengkap}-{from}-{to}.{xlsx|pdf}`. | Diterima | ADR 0012 |
| Q53 | Snapshot harga modal | `order_items.unit_cost` = `products.cost_price` saat checkout (null bila belum diisi), agar HPP & laba kotor tetap akurat walau harga modal berubah. Transaksi lama diisi dari harga modal produk saat migrasi. Harga opsi belum punya modal. Laporan **Laba kotor** (tab Laporan & `GET /reports/profit`, `report.view`): penjualan bersih per item = line_total × (grand_total − pajak − service − pembulatan) / subtotal (total = net_sales Ringkasan); item tanpa harga modal tidak dihitung dalam laba/margin dan dilaporkan terpisah. | Diterima | — |
| Q54 | Akses kasir web | Kasir web (`/cashier`) cadangan bila kasir/tablet tidak ada. Boleh dipakai user aktif yang punya `order.create` **dan** (`user.manage` — owner, selalu — **atau** `users.web_cashier_access` = true). Akses diatur owner di menu Karyawan (bawaan aktif untuk kasir & supervisor, nonaktif untuk manager). Login: email + kata sandi (owner/manager) atau kode bisnis + username + kata sandi (supervisor/kasir), sesi terpisah dari `/dashboard`. Satu browser = satu device `platform = web` di outlet terpilih (shift kas per browser); platform `web` tidak bisa didaftarkan lewat API `/devices`. Fitur: buka/tutup shift (X/Z-report, tutup paksa bila shift dibuka orang lain), transaksi (opsi; produk & pilihan opsi sama tanpa catatan dan tanpa diskon item digabung ke satu baris, selain itu baris baru; diskon, split payment dengan kartu metode bayar, struk lewat dialog cetak browser), **tahan transaksi** (maks. 10 keranjang per browser, disimpan di sesi: belum jadi order & belum memotong stok, ID order ikut kembali saat dilanjutkan), open bill, void & diskon di atas batas dengan PIN approver (penghitung PIN per device browser), toggle produk habis. Tanpa mode offline. Menu dashboard **Pengaturan → Perangkat** (`device.manage`): ganti nama, cabut (token & sesi langsung tidak berlaku; ditolak bila shift masih terbuka), aktifkan lagi. | Diterima | — |
| Q55 | Menu Kasir di dashboard | Sidebar dashboard **Transaksi → Kasir** (tampil bila `canUseWebCashier()`, Q54) membuka `/cashier/confirm` di tab baru. Identitas = user sesi `/dashboard`; wajib masuk ulang dengan **kata sandi** atau **PIN** (tab PIN hanya bila user punya PIN). PIN hanya untuk jalur ini, bukan login `/cashier` langsung. PIN memakai penghitung & kunci `VerifyPin` (sumber per IP); kata sandi maks. 5 salah/menit per user + IP. Tanpa sesi dashboard → login kasir biasa; **Keluar** dari kasir kembali ke dashboard bila sesinya masih aktif. Tab cara masuk (username/email, kata sandi/PIN) dipilih di form, tidak tampil di URL. Pilih outlet: lebih dari 3 outlet → dropdown dengan pencarian (urut nama). Halaman masuk kasir menampilkan versi aplikasi. Halaman kasir tidak punya link lupa kata sandi/PIN: akun terkunci dibuka owner di dashboard (Karyawan → Buka kunci PIN). | Diterima | — |
| Q56 | Target omzet per outlet | `outlets.daily_sales_target` & `monthly_sales_target` (DECIMAL(15,2), null/0 = tanpa target), diatur owner di **Profil outlet** atau `PUT /v1/outlet` (`outlet.settings`). Beranda menampilkan widget **Target omzet**: progres hari ini & bulan berjalan per outlet menurut zona waktu outlet (omzet = `grand_total` order selesai, void tidak dihitung), tidak mengikuti filter periode. Bulanan diberi status "sesuai/di bawah jalur" dibanding persen hari bulan yang sudah lewat. Belum ada target: ajakan mengatur untuk yang berizin, selain itu widget disembunyikan. | Diterima | `docs/api/settings.md` |
| Q57 | Ringkasan harian email | Command `pos:daily-summary` (jadwal tiap jam lewat cron `schedule:run`) mengirim rekap **kemarin** sekali per tanggal lokal, setelah jam `POS_DAILY_SUMMARY_HOUR` (bawaan 07.00) di zona waktu outlet pertama user. Isi per outlet aktif yang boleh diakses: omzet, transaksi, rata-rata, void, selisih kas shift yang ditutup, capaian target harian (Q56), plus daftar "Perlu dicek". Penerima: user aktif, `users.daily_summary_email` = true (bawaan owner; toggle di Profil, hanya bila punya `report.view`), email terverifikasi, tenant aktif. Tanpa aktivitas kemarin = tidak dikirim. Klaim atomik `last_daily_summary_on` mencegah email ganda. Email lewat antrean database. | Diterima | — |
| Q58 | Riwayat perubahan (audit log) | `spatie/laravel-activitylog` dengan model `ActivityLog` (morph UUID, `tenant_id` dari data yang diubah, dibaca lewat TenantScope). Dicatat (dibuat/diubah/dihapus, hanya kolom yang berubah, nilai lama → baru, pelaku = user login): produk (nama, SKU, barcode, harga, harga modal, kategori, stok, aktif, favorit), kategori, grup opsi, opsi (tambahan harga), metode bayar, setelan outlet (termasuk pajak, batas diskon, target), karyawan (nama, email, username, role, aktif, akses kasir web). Kata sandi/PIN hanya dicatat "diganti" tanpa nilai. Menu **Pengaturan → Riwayat perubahan** (`audit.view`: owner & manager; jenis data mengikuti izin pengelolanya, outlet dibatasi ke yang boleh diakses), tautan dari panel Audit Beranda. Disimpan 365 hari (`activitylog:clean` harian). Void & diskon ber-approval tetap di panel Audit (data order). | Diterima | — |
| Q59 | Import produk dari Excel | Dashboard **Produk → Import** (`product.manage`): template `.xlsx` (kolom `nama, kategori, harga, harga_modal, sku, barcode, lacak_stok, stok_awal, aktif`), unggah `.xlsx`/`.csv` maks. 2 MB & 1.000 baris. Semua baris divalidasi dulu; satu salah = tidak ada yang disimpan (error per nomor baris). SKU yang sudah ada (tidak peka huruf besar) = produk diperbarui (grup opsi, favorit, stok & stok minimum tetap); selain itu produk baru dijual di semua outlet, stok awal untuk outlet aktif. Kategori dicari per nama, dibuat bila belum ada. Harga menerima `24000`, `24.000`, `Rp 24.000`, `24.000,50`. Teks diawali `= + - @` ditolak (formula injection). Satu transaksi, tiap produk lewat `SaveProduct` (aturan & audit log sama dengan form). File sementara dihapus setelah diproses. Tanpa endpoint API. | Diterima | — |
| Q60 | Cakupan member | Member milik **bisnis (tenant)**, berlaku di semua outlet; owner bisa mengecualikan outlet (`outlet_member_program`, tanpa baris = ikut). Tidak pernah lintas tenant. Outlet tidak ikut: member bisa dipasang (kunjungan) tanpa poin/stempel/diskon/voucher. | Diterima | ADR 0013 |
| Q61 | Identitas & data member | Nomor HP dinormalisasi `+62…` unik per tenant, nama wajib; email & tanggal/bulan lahir opsional; `code` 6 karakter acak untuk QR. Persetujuan wajib (`consent_at`, UU PDP). Hapus atas permintaan = anonimkan data pribadi, transaksi & ledger tetap. Kasir melihat HP tersamar. | Diterima | ADR 0013 |
| Q62 | Poin | Kumpul dari subtotal setelah diskon, sebelum pajak/service/pembulatan, saat order **selesai**; × pengali tier. Tukar sebagai **metode bayar kategori `points`** (pajak dari harga penuh, tidak masuk kas laci, tanpa kembalian), dengan minimal poin & maksimal persen tagihan. Kedaluwarsa per mutasi (bawaan 12 bulan, FIFO). Ledger append-only, idempotent per order/pembayaran; void menarik poin kumpul & mengembalikan poin tukar (saldo boleh minus). Bukti tukar: QR dinamis halaman member (30 detik) atau PIN member 4 digit / PIN supervisor. | Diterima | ADR 0013 |
| Q63 | Diskon member & urutan potongan | Diskon member % tetap (+ diskon tier) dicatat sebagai diskon order. Urutan di `OrderCalculator`: diskon item manual → gratis stempel → diskon order manual → voucher → diskon member/tier → service → pajak → pembulatan → pembayaran (termasuk Poin). | Diterima | ADR 0013 |
| Q64 | Tier member | Tingkatan (mis. Reguler/Silver/Gold) diatur owner: batas **total belanja 12 bulan bergulir**, pengali poin & diskon. Dievaluasi saat order selesai & jadwal harian (bisa naik/turun). Implementasi M5: belanja = Σ `grand_total` order selesai (void tidak dihitung) 12 bulan terakhir; di bawah tier terendah = tanpa tier (tidak ada baris "Reguler" wajib); diskon tier **ditambahkan** ke diskon member dasar (maks. 100%); pengali poin memakai tier **sebelum** order dihitung (tier baru berlaku di transaksi berikutnya); order menyimpan snapshot `member_tier`; `pos:evaluate-member-tiers` harian 01.45 WIB. | Diterima | ADR 0013 |
| Q65 | Kartu stempel | Program per produk/kategori terpilih: 1 item = 1 stempel; N stempel = 1 item gratis (item termurah yang memenuhi, dicatat diskon item). Beberapa program bisa aktif. Stempel masuk saat selesai, dikembalikan saat void. Implementasi M6: kasir memilih kartu (`stamp_program_ids`, maks. 5, 1 gratis per kartu per order); harga satuan termurah setelah diskon item menjadi potongan `stamp_free_total` sebelum diskon order manual (bagian `discount_total`, bukan baris item); satu unit tidak digratiskan dua kartu; unit gratis tidak menghasilkan stempel; saldo dicek ulang saat order selesai (stempel tidak terpakai dua kali), gagal = `422 VALIDATION_ERROR` field `stamp_program_ids`; tanpa bukti QR/PIN (nilai kecil, seperti memasang member). | Diterima | ADR 0013 |
| Q66 | Voucher | Voucher **ulang tahun** otomatis di bulan lahir (sekali, sampai akhir bulan) dan **kode promo owner** (nominal/persen, maks. potongan, minimal belanja, kuota total & per member, periode, outlet, khusus member/tier). Dicatat sebagai diskon order; void mengembalikan kuota.  Implementasi M7: kode tidak peka huruf besar; voucher berlaku hanya di outlet yang ikut program; kode promo tanpa syarat member boleh untuk transaksi tanpa member, `per_member_quota` atau tier = otomatis khusus member; minimal belanja dibanding subtotal setelah diskon item; pemakaian dicatat saat order selesai dengan kuota dicek ulang di bawah kunci; voucher ulang tahun dibuat `pos:issue-birthday-vouchers` (harian 00.15 WIB) untuk bulan lahir WIB, sekali per bulan; gagal = `422 VOUCHER_INVALID` + `details.reason`.| Diterima | ADR 0013 |
| Q67 | Halaman member | `gspos.id/m/{kode-bisnis}` (per bisnis), guard `member`. Login **nomor HP + kata sandi**; daftar mandiri (nomor yang sudah didaftarkan kasir diaktifkan lewat kata sandi sementara) (tanpa OTP/email); lupa kata sandi = kasir/owner membuat kata sandi sementara (wajib diganti). Pesan umum, batas percobaan & kunci 15 menit. Isi: QR dinamis, saldo & riwayat poin, tier, stempel, voucher, transaksi, profil, ganti kata sandi, hapus akun. | Diterima | ADR 0013 |
| Q68 | Alur self-order meja | **Terima kasir dulu, lalu bayar** (bayar dulu sebelum diproses): `pending` → kasir terima (`awaiting_payment`) atau tolak (`rejected` + alasan) → lunas online/tunai (`completed`, stok dipotong saat lunas) atau `expired` setelah `payment_timeout_minutes` (bawaan 15). Masuk shift kasir yang menerima; `orders.channel = self_order`; harga dihitung ulang server, snapshot saat diterima. | Diterima | ADR 0014 |
| Q69 | Meja & QR | Tabel `tables` per outlet (nama, area, `qr_token` acak, aktif). QR `order.gspos.id/t/{token}` (tanpa subdomain `/order/t/{token}`); token bisa diganti; cetak QR per meja/semua dari dashboard (`table.manage`). Setelan per outlet: aktif, batas waktu bayar, jam layanan, metode online. | Diterima | ADR 0014 |
| Q70 | Halaman pesan HP | Web tanpa instal: menu sesuai katalog outlet (ADR 0011), opsi & catatan; tamu (nama) atau member (login, ADR 0013). Status di HP lewat polling: menunggu kasir → bayar (Midtrans Snap atau bayar di kasir) → lunas (struk digital) / ditolak / kedaluwarsa. Batas: 3 pesanan pending per meja, 10 pesanan/jam per IP, 50 item; idempotent lewat `id` klien. | Diterima | ADR 0014 |
| Q71 | Menu kasir self-order | Kasir web & tablet: **Pesanan masuk** (pending, terima/tolak, badge + suara) dan **Belum dibayar** (awaiting_payment + open bill: terima tunai/metode lain, batalkan). Notifikasi polling 5–10 detik (tanpa WebSocket, ADR 0004). Izin `self_order.handle`. | Diterima | ADR 0014 |
| Q72 | Midtrans per owner | Owner menghubungkan akun Midtrans sendiri (Pengaturan → Pembayaran online, `payment_gateway.manage`): key terenkripsi, tidak ditampilkan ulang, sandbox/production, tes koneksi. Dana langsung ke owner; MDR ditanggung owner. Webhook per tenant diverifikasi `signature_key`, dicek ulang ke API Midtrans, nominal dicocokkan, idempotent. | Diterima | ADR 0014 |
| Q73 | Metode bayar online & refund | Kategori pembayaran baru `online` (otomatis saat Midtrans aktif): tidak masuk kas laci, referensi = transaction_id Midtrans, dilaporkan terpisah. Tanpa refund otomatis: void order online lunas → tanda "perlu refund manual". | Diterima | ADR 0014 |
| Q74 | Urutan pengerjaan member & self-order | Member M1–M7 (ADR 0013) lalu self-order S1–S4 (ADR 0014). Izin & kode error baru (ADR 0013/0014) masuk tabel resmi SPEC bersamaan dengan implementasinya. Pajak poin sebagai metode bayar perlu dikonfirmasi ke konsultan pajak klien. | Diterima | ADR 0013, ADR 0014 |
| Q75 | Laporan, ringkasan & import/export member | Tab Laporan **Member** & `GET /reports/members` (`report.view`; tampil bila program member pernah diatur): member vs non-member, member aktif/berulang/baru (member baru per outlet daftar), poin didapat/ditukar/kedaluwarsa, utang poin saat ini (saldo aktif × nilai poin, seluruh bisnis), voucher, gratis stempel, 20 member teratas, sebaran tier; ikut export Excel/PDF. Ringkasan harian email (Q57) memuat transaksi & omzet member, member baru, poin ditukar bila program aktif. **Import** member Excel/CSV (`member.manage`, maks. 1.000 baris, semua baris valid atau tidak ada yang disimpan, nomor HP terdaftar = diperbarui tanpa mengubah kode/poin, pengimpor wajib menyatakan persetujuan pelanggan) & **export** Excel (tanpa member yang dihapus, dibatasi seperti export laporan). | Diterima | ADR 0013 |
| Q76 | Implementasi self-order S1–S2 | Pesanan dari HP disimpan di `self_orders` (pending/rejected/expired) karena order wajib punya shift & kasir; **order dibuat saat kasir menerima** (status `awaiting_payment`, `channel = self_order`, shift & perangkat kasir penerima, nomor order, harga & member/voucher dihitung ulang), stok dipotong saat lunas. URL QR `gspos.id/order/t/{token}` (domain utama, tanpa subdomain baru — pola halaman member). Tabel meja `dining_tables`. Cetak QR = halaman cetak browser (bukan file PDF). Halaman HP Livewire: menu outlet, opsi, keranjang, nama, catatan, kode voucher, member dari sesi halaman member (link “Masuk sebagai member” kembali ke meja), status polling 5 detik; pesanan sesi disimpan di session per meja. Kasir web: menu **Pesanan meja** (badge polling 10 detik + bunyi; terima/tolak; Belum dibayar → Bayar di layar Transaksi dengan item terkunci). Batas waktu (`payment_timeout_minutes`) berlaku untuk pesanan menunggu kasir (sejak dikirim) dan order belum dibayar (sejak diterima, hanya bila belum ada pembayaran); `pos:expire-self-orders` tiap menit. Batal pesanan yang sudah diterima = void (PIN, Q18). | Diterima | ADR 0014 |
| Q77 | Implementasi Midtrans (S3) | Pengaturan → **Pembayaran online** (`payment_gateway.manage`): aktif, sandbox/production, Server & Client Key (cast `encrypted`, kosong = tetap, tidak pernah dikirim ke tampilan), metode Snap, **Tes koneksi** (401 = key salah), URL notifikasi `POST /webhooks/midtrans/{tenant_id}` untuk dipasang owner di dashboard Midtrans. Aktif = metode sistem **Online** dibuat (tidak muncul di katalog/kasir; dicatat kasir/API → `422` field metode bayar). HP: tombol **Bayar online** untuk pesanan yang sudah diterima → redirect Snap (nominal = sisa tagihan server, dibulatkan ke atas ke rupiah; Snap yang masih berlaku dipakai ulang). Webhook: tanda tangan SHA512 → transaksi milik tenant → status **dicek ulang** ke API Midtrans → nominal cocok → pembayaran `online` atas nama kasir penerima + order selesai di shift penerima (idempotent). Dibayar setelah kedaluwarsa = tetap diselesaikan; dibayar setelah void/lunas = `refund_required` (tanpa refund otomatis); void order yang lunas online juga menandai `refund_required` (tampil “Perlu refund manual” di Penjualan). Order kedaluwarsa membatalkan Snap yang belum dibayar. Midtrans tidak bisa dihubungi saat cek ulang → webhook dijawab 503 agar dikirim ulang. | Diterima | ADR 0014 |
| Q78 | Self-order S4 | API aplikasi kasir: `GET /self-orders`, `POST /self-orders/{id}/accept`, `POST /self-orders/{id}/reject`; Belum dibayar = `GET /orders?status=awaiting_payment&channel=self_order`, dibayar lewat `POST /orders/{id}/payments`; field order `channel`, `dining_table_id`, `accepted_at`, `refund_required`. Laporan **per kanal** (tab Tipe order, `GET /reports/channels`, export) & filter Kanal + tab “Menunggu bayar” di Penjualan. Member yang masuk di HP bisa **membayar dengan poin** (bukti = sesi member; poin sebanyak aturan program izinkan, sisa dibayar online/kasir). | Diterima | ADR 0014 |
| Q79 | Stok masuk & supplier | Produk jadi saja (bahan baku/resep Fase 2). Dashboard **Stok → Stok masuk** (`stock.receive`: owner, manager, supervisor — supervisor mendapat menu ini walau dashboard-nya hanya Laporan): dokumen per outlet (tanggal terima, supplier opsional, nomor nota, catatan, item produk lacak stok × qty bulat > 0 × harga beli/unit). Draf bisa diubah/dihapus; **Posting** menambah stok outlet (riwayat `purchase`) & menghitung modal; setelah diposting tidak bisa diubah, koreksi = **Batalkan** dengan alasan (stok dikurangi, riwayat `purchase_void`, modal tidak diputar balik). **Stok → Supplier** (`supplier.manage`): nama unik per bisnis, kontak, alamat, catatan, aktif. Tanpa PO & utang supplier. | Diterima | ADR 0015 |
| Q80 | Modal rata-rata tertimbang | Saat stok masuk diposting: `modal baru = (Σ stok semua outlet × modal lama + qty × harga beli) ÷ (Σ stok + qty)`; stok minus = 0; modal kosong = harga beli; dibulatkan 2 desimal; `cost_before`/`cost_after` disimpan per baris & tercatat di Riwayat perubahan produk. Beberapa baris produk sama dalam satu dokumen dihitung berurutan. | Diterima | ADR 0015 |
| Q81 | Stok opname | Dashboard **Stok → Stok opname** (`stock.opname`: owner, manager): **Buat lembar hitung** untuk outlet (semua produk lacak stok yang dijual di outlet atau satu kategori, maks. 1.000) dengan stok sistem saat itu; isi qty fisik (bilangan bulat ≥ 0, kosong = tidak dihitung); **Posting**: selisih = fisik − stok outlet saat posting (penjualan setelah lembar dibuat ikut terhitung), stok diset ke fisik dengan riwayat `opname` (hanya bila selisih ≠ 0), nilai selisih = selisih × harga modal saat itu; tidak bisa diubah/dihapus setelah diposting (koreksi lewat penyesuaian/opname baru); draf bisa dihapus. | Diterima | ADR 0015 |
| Q82 | Transfer antar outlet | Dashboard **Stok → Transfer** (`stock.transfer`: owner, manager; tampil bila ≥ 2 outlet aktif). Daftar = transfer dari/ke outlet yang dipegang user. **Draf** (pembuat memegang outlet asal; tujuan = outlet aktif lain bisnis ini; produk lacak stok, qty bulat > 0, produk sama digabung) → **Kirim** (pemegang outlet asal: stok asal berkurang, riwayat `transfer_out`, status dalam perjalanan) → **Terima** (pemegang outlet tujuan: isi qty diterima 0–dikirim, stok tujuan bertambah sebesar yang diterima, riwayat `transfer_in`; selisih = hilang/rusak di jalan, tidak kembali ke asal). **Batalkan** hanya saat dalam perjalanan (pemegang asal, alasan wajib, stok kembali ke asal, riwayat `transfer_cancel`); draf dihapus; sudah diterima final. Modal tidak berubah. | Diterima | ADR 0015 |
| Q83 | Laporan stok & pembelian | Tab Laporan **Stok** (`report.view`, outlet terpilih): kolom baru Stok masuk (bersih setelah batal), Opname, Transfer (bersih masuk/keluar outlet itu), **Nilai stok** saat ini (stok positif × harga modal) + total; tabel **Pembelian per supplier** (dokumen, qty, nilai beli) & **per produk** (qty, rata-rata harga beli, nilai beli) dari stok masuk yang diposting & tidak dibatalkan menurut tanggal terima lokal; ikut export Excel/PDF; API `GET /reports/purchases`. | Diterima | ADR 0015 |
| Q84 | Profil & menu akun dashboard | Menu akun (nama + avatar di kanan atas) hanya **Profil**, **Bantuan** (WhatsApp `POS_SUPPORT_WHATSAPP` atau email `POS_SUPPORT_EMAIL`), **Keluar**; pengalih tema (terang → gelap → sistem) berupa tombol di kiri nama, bukan di dalam menu. Halaman Profil dua kolom: kiri kartu profil (foto, nama, role · bisnis, email) + **Info akun**; kanan **Data diri**, Keamanan, Notifikasi, Perangkat & sesi; label di atas kolom. Foto JPG/PNG/WebP maks. 2 MB, dipotong persegi 1:1 (tampil bulat hanya di avatar topbar), disimpan di disk `public` folder `avatars/{tenant_id}`, foto lama dihapus saat diganti/dihapus. Data diri: nama, email, nomor HP opsional dinormalisasi +62. Info akun baca saja: outlet, username, status email, terakhir masuk, bergabung. **Perangkat & sesi** (sesi browser & token aplikasi; **Keluar dari semua perangkat lain** menghapus sesi lain & semua token API, browser ini tetap masuk). Ikon di dalam kolom isian 16px. | Diterima | — |
| Q85 | URL pendek QR meja | Setiap meja punya `short_code` 6 karakter (huruf besar & angka tanpa 0/O/1/I/L, unik global). `gspos.id/t/{kode}` (tidak peka huruf besar/kecil, throttle 60/menit) mengalihkan ke URL asli `/order/t/{token}` yang tetap berlaku; meja nonaktif/kode tidak dikenal = 404. QR cetak memuat URL pendek; **Ganti QR** mengganti token dan kode. Kartu cetak 60 mm (3 per baris A4): nama bisnis & outlet satu baris (dipotong “…”), nama meja, area, QR, URL pendek. | Diterima | ADR 0014 |
| Q86 | Installer web | `/install` (ADR 0016) bila aplikasi belum terinstal: kode instal opsional (`INSTALL_TOKEN`), cek syarat, database kosong (Tes koneksi), aplikasi (URL, edisi bawaan paket, zona waktu, subdomain & SMTP opsional), akun (super admin; bisnis + owner wajib untuk jual putus; manager opsional), proses per langkah lewat AJAX (lanjut setelah refresh), kunci `storage/app/installed.json` lalu ke login. Sebelum terinstal web → `/install`, API `503 MAINTENANCE`. Paket zip per edisi dari workflow Release (`docs/install.md`). | Diterima | ADR 0016 |
| Q87 | Shift bersama per perangkat | ADR 0017. Shift milik perangkat; **ganti kasir tanpa hitung**: tombol **Kunci** (atau kunci otomatis) → karyawan yang ditugaskan di outlet pilih nama + PIN (kasir web) / login PIN (aplikasi) → operator berganti, shift & laci tetap. Log operator `shift_operators` (mulai/selesai) dari buka shift, login PIN/email di device, buka kunci, dan pilih outlet kasir web; diakhiri saat tutup. Tutup biasa oleh siapa saja yang bertugas di device itu (`shift.operate`); tutup paksa (`shift.force_close`) dari device lain/dashboard. Kasir melihat order shift yang terbuka di perangkatnya; rekap shift bisa dilihat pembuka & operatornya. Selisih kas ditanggung bersama (atas nama shift). | Diterima | ADR 0017 |
| Q88 | Setoran & carry-over kas awal | Tutup shift: Kas dihitung (`actual_cash`) + **Disetor** (`cash_deposited`, 0 ≤ disetor ≤ dihitung; kosong = dihitung − modal laci tetap outlet bila diatur, selain itu 0); `closing_float` = dihitung − disetor. Buka shift: kas awal = `closing_float` shift terakhir yang ditutup di **perangkat yang sama** (`opening_cash_source` `carry_over`), tanpa diketik; perangkat tanpa shift sebelumnya / shift lama tanpa `closing_float` → diketik (`manual`). Angka berbeda saat buka, atau koreksi saat shift berjalan (`POST /shifts/{id}/opening-cash`, hanya dari device shift), = `adjusted`: alasan wajib + `shift.edit_opening_cash` atau PIN approver (≠ pelaku); nilai semula di `carried_cash`, pelaku/approver/waktu tercatat & tampil di panel Audit. | Diterima | ADR 0017 |
| Q89 | Kas masuk/keluar | `cash_movements` per shift: jenis `in`/`out`, alasan `operational` (Belanja operasional, keluar), `deposit` (Setor ke owner, keluar), `change` (Tukar/tambah kembalian), `other` (Lainnya, catatan wajib), nominal > 0, catatan maks. 255; `id` dari klien = idempotency key. Hanya di shift terbuka device pelaku; tidak bisa diubah/dihapus. Kas keluar tidak boleh melebihi kas seharusnya di laci (`VALIDATION_ERROR`); di atas `outlets.cash_out_approval_limit` (bawaan Rp100.000, kosong = tanpa approval) kasir butuh PIN approver (`APPROVAL_REQUIRED`, `shift.cash_out_over_limit`). Masuk ke `expected_cash`, X/Z-report, laporan Shift, panel Audit (kas keluar). | Diterima | ADR 0017 |
| Q90 | Setelan laci kasir | Profil outlet → **Laci kasir** (`outlet.settings`, juga `PUT /outlet` & `outlet` di `/auth/me`): `cash_float` modal laci tetap (opsional), `auto_lock_minutes` kunci layar otomatis (bawaan 5, kosong/0 = mati, maks. 120; dihitung di browser/aplikasi dari ketikan/sentuhan, polling tidak dihitung), `cash_out_approval_limit` (bawaan Rp100.000), `refund_max_days` batas hari retur (bawaan 30, 0–365, 0 = retur tidak diizinkan; ADR 0018). | Diterima | ADR 0017 |
| Q91 | Z-report shift bersama | X/Z-report (web, PDF, API `reports/shifts/{id}`) menampilkan asal kas awal (sisa laci / dikoreksi + alasan), kas masuk & keluar (rincian), disetor, ditinggal di laci, dan **Kasir bertugas** (jam bertugas dari log operator, transaksi, omzet, tunai per kasir). Laporan Shift & export menambah kolom kas masuk, kas keluar, disetor; ringkasan harian email menambah total disetor. | Diterima | ADR 0017 |
| Q92 | Retur sebagian | ADR 0018. `POST /orders/{id}/refunds` & kasir web **Riwayat → Retur transaksi** (`/cashier/refunds`, cari nomor): order **selesai** di outlet perangkat, ≤ `outlets.refund_max_days` hari (bawaan 30, 0 = retur tidak diizinkan; diatur di Profil outlet → Laci kasir / `PUT /outlet`); item & qty ≤ terjual − sudah diretur; alasan wajib; cara `cash` (kas keluar alasan **Retur** di shift berjalan perangkat pelaku, ≤ isi laci) atau `manual`; `restock` (bawaan ya) mengembalikan stok produk lacak stok (riwayat `refund`); izin `order.refund` (owner, manager, supervisor) atau PIN approver ≠ pelaku; shift wajib terbuka; `id` dari klien = idempotency key. Order tetap `completed`, `refunded_total` & `order_items.refunded_qty` bertambah; order yang sudah diretur tidak bisa di-void. | Diterima | ADR 0018 |
| Q93 | Nilai retur & member | Nilai per baris = `grand_total × line_total × qty_retur ÷ (qty × subtotal)` (2 desimal, `OrderCalculator::refundShare`); retur yang menghabiskan seluruh item = sisa `grand_total − refunded_total`. Bagian yang dibayar **poin** kembali sebagai poin, poin hasil belanja ditarik — keduanya proporsional kumulatif, dibulatkan ke bawah (ledger `refund`); `payout_amount` = nilai − bagian poin. Belanja member & tier (12 bulan = Σ grand_total − retur) dikurangi; voucher & stempel tidak dikembalikan. | Diterima | ADR 0018 |
| Q94 | Laporan retur | Ringkasan/Laporan: `refund_count`, `refund_total`, `net_revenue` (omzet − retur, menurut tanggal retur), ikut export; panel Audit daftar **Retur** (kas keluar alasan Retur tidak masuk kartu Kas keluar); ringkasan harian baris retur; detail order dashboard & kasir web menampilkan riwayat retur; uang retur tunai tampil di kas masuk/keluar shift. Laporan per produk/kategori tetap penjualan bruto. `GET /refundable-orders/{nomor}` untuk aplikasi. | Diterima | ADR 0018 |
| Q95 | Pecah open bill | ADR 0019. `POST /orders/{id}/split` (`new_order_id` dari klien = idempotency key, `items[]` `{order_item_id, qty}`, `table_label` opsional) & kasir web detail open bill → **Pecah bill**: item & qty pindah ke open bill baru (nomor baru, shift perangkat pelaku), bill asal minimal tersisa 1 item, member tetap di bill asal. Syarat bill: `open` di outlet perangkat, belum ada pembayaran, tanpa voucher & kartu stempel (`VALIDATION_ERROR`). Diskon manual ikut: diskon item dibagi proporsional qty (sisa pembulatan di bill asal), diskon order persen di kedua bill, nominal tetap di bill asal; `approved_by` lama ikut tanpa PIN ulang (total diskon tidak bertambah). Kedua bill dihitung ulang lewat `SaveOpenBill` (harga terkini). Izin `order.create`. Pindah meja = ubah `table_label` open bill. | Diterima | ADR 0019 |
| Q96 | Gabung open bill | `POST /orders/{id}/merge` (`source_order_id`) & kasir web → **Gabung bill**: semua item sumber ditambahkan ke tujuan (dihitung ulang), sumber berstatus baru **`merged`** (label "Digabung", `merged_into_id`), tidak masuk laporan. Syarat sama dengan pecah; diskon item ikut; diskon order persen sama di kedua bill tetap persen, selain itu nominal = jumlah `order_discount` kedua bill; `approved_by` tujuan (atau sumber) ikut; member: tujuan, atau sumber bila tujuan tanpa member; dua member berbeda → `VALIDATION_ERROR`. Gabung ulang yang sama = hasil sama. | Diterima | ADR 0019 |
| Q97 | Bahan baku & satuan | ADR 0020. `products.kind`: `regular` (dijual), `ingredient` (bahan: tidak dijual, tidak tampil di katalog/kasir/self-order/scan barcode, stok selalu dilacak, tanpa grup opsi & resep), `bundle` (paket). `products.unit` (bawaan `pcs`); stok & resep bilangan bulat dalam satuan itu. API `kind`, `unit` opsional (tidak dikirim = tidak diubah; baru = `regular`/`pcs`); `GET /products?kind=`. Dashboard Produk: field Jenis & Satuan stok, tab **Bahan baku** & **Paket**. | Diterima | ADR 0020 |
| Q98 | Resep & opsi berbahan | `components[]` `{component_id, qty 1–1.000.000}` maks. 50 per produk (tidak dikirim = tidak diubah): komponen produk biasa/bahan tenant ini, bukan paket, bukan dirinya, tanpa siklus (resep dipecah sampai 3 tingkat). Produk biasa dengan resep & paket tidak melacak stok sendiri. Opsi: `options[].components[]` (API) / satu bahan per opsi (form dashboard). Saat order selesai stok yang dipotong = komponen × qty (+ bahan opsi), riwayat `sale` per komponen; void membalik persis riwayat `sale` order; retur mengembalikan resep saat ini × qty. HPP `order_items.unit_cost` = Σ modal komponen × qty (+ opsi), tanpa resep = `cost_price`. | Diterima | ADR 0020 |
| Q99 | Paket | `kind: bundle` wajib minimal 1 komponen; harga paket sendiri, stok dipotong dari isi (isi beresep dipecah ke bahannya). Produk yang sudah menjadi komponen tidak bisa diubah menjadi paket. Laporan penjualan mencatat paket sebagai satu item. | Diterima | ADR 0020 |
| Q100 | Purchase order | ADR 0021. Dashboard **Stok → Purchase order** (`purchase.manage`: owner, manager): draf per outlet, supplier aktif wajib, tanggal pesan & perkiraan datang, item produk/bahan lacak stok (qty bulat, harga beli perkiraan; produk sama digabung), nomor `{outlet}-PO-{YYMMDD}-{urut}`. **Kirim** mengunci isi; **Batalkan** (alasan) untuk PO dikirim/diterima sebagian; draf dihapus. Tab **Menunggu barang**. Tanpa API. | Diterima | ADR 0021 |
| Q101 | Terima barang PO | Aksi **Terima barang** (`stock.receive`) membuat draf Stok masuk berisi sisa qty & harga PO (`stock_receipts.purchase_order_id`); qty/harga boleh diubah. Posting/batal stok masuk itu menghitung ulang `received_qty` per produk & status PO (**Diterima sebagian** / **Diterima**; boleh lebih dari dipesan). PO dibatalkan tidak membatalkan stok masuk yang sudah diposting. | Diterima | ADR 0021 |
| Q102 | Promo otomatis | ADR 0022. Dashboard **Pelanggan → Promo** (`promo.manage`: owner, manager): `item_discount` (persen ≤ 100 / nominal per unit, semua/produk/kategori), `buy_get` (beli X gratis Y 1–100, Y unit termurah gratis per kelompok X+Y), `order_discount` (subtotal setelah promo item ≥ minimal → persen dengan maks. / nominal). Jadwal: aktif, tanggal mulai–selesai, hari (ISO 1–7), jam mulai–selesai waktu outlet (selesai < mulai = lewat tengah malam), outlet tertentu/semua. Riwayat perubahan dicatat. | Diterima | ADR 0022 |
| Q103 | Hitung promo | `AutomaticPromotions` (saat harga dihitung: checkout, open bill, self-order, pratinjau): per baris satu diskon item terbesar (maks. nilai baris); baris berdiskon item tidak ikut beli X gratis Y; antar promo beli-gratis unit tidak dipakai dua kali; satu diskon order terbesar; total ≤ subtotal. Dasar = subtotal − stempel − **promo** − diskon order − voucher − member; promo tidak masuk batas diskon kasir (Q18). Snapshot `orders.promo_discount` & `orders.promotions` [{promotion_id, name, amount}] (ikut `discount_total`); struk & keranjang kasir web menampilkan nama promo. Open bill memakai promo saat terakhir disimpan. | Diterima | ADR 0022 |
| Q104 | Promo di aplikasi | `GET /catalog` → `promotions[]` (promo aktif outlet yang belum berakhir, beserta aturan & jadwal) untuk ditampilkan; `POST /orders/quote` (items, discount, member_id, voucher, stamp_program_ids) → total dari server termasuk `promo_discount` & `promotions`. Order & struk: `promo_discount`, `promotions`. | Diterima | ADR 0022 |
| Q105 | Kasbon member | ADR 0023. Pengaturan → Program member → **Kasbon** (aktif + batas bawaan); metode bayar sistem **Kasbon** (`receivable`, label "Kasbon") aktif mengikuti setelan & tersembunyi di menu Metode bayar. Hanya member terpasang + bukti QR/PIN member (QR dipakai sekali untuk poin & kasbon). Batas `members.credit_limit` (kosong = bawaan; 0/kosong semua = tidak boleh); saldo + kasbon ≤ batas, member dikunci. Ledger `receivable_entries` (`charge`/`payment`/`void`/`refund`), saldo `members.receivable_balance`. Void membalik kasbon order; retur mengurangi kasbon sebesar bagian yang dibayar kasbon (`payout_amount` hanya bagian uang). | Diterima | ADR 0023 |
| Q106 | Pelunasan kasbon | `POST /members/{id}/receivable-payments` `{id, amount ≤ saldo, payment_method_id (bukan poin/online/kasbon), note?}` (`order.create`, device; `id` = idempotency key), kasir web **Kasbon** (`/cashier/receivables`), dashboard Member → **Catat pelunasan** (`member.manage`, tanpa laci). Tunai di perangkat = kas masuk alasan sistem **Pelunasan kasbon** di shift terbuka (masuk `expected_cash`). `GET /members/{id}/receivables` riwayat 50 terbaru. | Diterima | ADR 0023 |
| Q107 | Laporan kasbon | Menu Member: kolom & riwayat Kasbon, aksi **Batas kasbon**; laporan Member: `receivable_total` & `receivable_members` (saat ini, semua outlet), ikut export. `GET /members/{id}` memuat `receivable_balance` & `credit_limit` (batas berlaku). | Diterima | ADR 0023 |
| Q108 | Paket langganan | ADR 0024. Panel /admin **Pelanggan → Paket**: kode unik `[a-z0-9-]`, nama, harga bulanan & tahunan, maks. outlet (kosong = tanpa batas), fitur (`member`, `self_order`, `stock`, `recipes`, `promotions`, `credit`), aktif, publik (tampil di halaman Langganan owner), urutan. **Atur paket** di detail bisnis (tanpa tagihan; kosong = semua fitur). Paket bawaan `PlanSeeder` (aman diulang, tidak menimpa): **Basic** Rp99rb/bln (1 outlet, 3 karyawan, 1 perangkat, 100 produk, fitur dasar), **Pro** Rp199rb (1 outlet, 10 karyawan, 3 perangkat, 500 produk, 2.000 member; member, promo, stok, resep, laporan lanjutan, export, kasir web), **Bisnis** Rp399rb (3 outlet, 30 karyawan, 10 perangkat, 2.000 produk, 10.000 member; semua fitur), **Enterprise** khusus Rp999rb (tanpa batas, semua fitur); tahunan = 10× bulanan. Detail bisnis (SaaS) menampilkan section **Langganan**: paket, siklus, maks. outlet, perubahan terjadwal, 5 tagihan terbaru + tautan **Semua tagihan** (filter Bisnis di Pelanggan → Tagihan). Daftar bisnis: kolom & filter **Paket** (termasuk "Tanpa paket"). Tenant tanpa paket & edisi jual putus = semua fitur. Fitur mati menyembunyikan menu terkait (Promo, Stok masuk/opname/transfer/PO/Supplier, Member/Voucher/Kartu stempel/Program member, Meja & QR/Pembayaran online, Jenis & resep produk) dan mematikan mesin (promo otomatis, kasbon, self-order). Kasir, laporan, retur, pecah bill, kas masuk/keluar selalu ada. | Diterima | ADR 0024 |
| Q109 | Tagihan | Dashboard **Pengaturan → Langganan** (`billing.manage`: owner; tetap bisa dibuka saat hanya-baca; hanya SaaS): paket, siklus, status, aktif sampai, batas outlet, fitur; **Buat tagihan** (paket aktif & publik atau paket saat ini, Bulanan/Tahunan) → `subscription_invoices` (nomor `INV-{YYMM}-{acak 6}`, nominal & nama paket snapshot, jatuh tempo `POS_BILLING_DUE_DAYS` 3 hari); tagihan menunggu lama dibatalkan otomatis. Jenis `kind`: **upgrade** (langganan berbayar aktif, paket lebih mahal, siklus sama) = (harga baru − lama) × sisa hari ÷ hari satu siklus, dibulatkan ke rupiah, jatuh tempo ≤ akhir masa aktif; selain itu **perpanjangan** harga penuh. Modal menampilkan pratinjau nominal & efeknya. Riwayat 24 tagihan terbaru (dengan jenis). Super admin: detail bisnis → **Buat tagihan** (semua paket aktif termasuk khusus/non-publik, pratinjau sama, opsi email `InvoiceCreated` ke owner aktif lewat antrean); pembuat tercatat `created_by_admin_id`, tampil "dibuat admin" di Pelanggan → Tagihan. | Diterima | ADR 0024 |
| Q110 | Bayar tagihan | **Bayar online**: Snap Midtrans vendor (`POS_BILLING_MIDTRANS_SERVER_KEY`, `POS_BILLING_MIDTRANS_PRODUCTION`), sekali per tagihan; webhook `POST /webhooks/billing/midtrans` (tanpa CSRF, tanda tangan SHA512 dengan key vendor, status dicek ulang ke API, nominal sama) — dibayar setelah jatuh tempo tetap diakui. Tanpa key: instruksi transfer `POS_BILLING_BANK_INFO`; admin **Pelanggan → Tagihan → Tandai lunas** (catatan) / **Batalkan**. Lunas upgrade: paket & `max_outlets` langsung diganti, masa aktif tetap (periode sekarang–akhir). Lunas perpanjangan: status aktif (ditangguhkan tetap ditangguhkan), `subscription_ends_at` = max(sekarang, akhir lama) + 1/12 bulan; paket & siklus langsung diganti bila trial/habis/paket sama, selain itu dijadwalkan (`next_plan_id`, `next_billing_cycle`, `plan_changes_at` = akhir lama; `pos:apply-plan-changes` tiap jam, tampil "Paket berikutnya" di Langganan; admin atur paket langsung = jadwal batal). Periode tercatat; idempotent. | Diterima | ADR 0024 |
| Q111 | Pengingat langganan | `pos:billing-reminders` harian 08.00 WIB: tagihan menunggu lewat jatuh tempo → `expired`; owner aktif ber-email diingatkan 7/3/1 hari sebelum `subscription_ends_at` (trial juga), sekali per bisnis per ambang. | Diterima | ADR 0024 |
| Q112 | Ringkasan langganan admin | Dasbor /admin (hanya SaaS), widget **Langganan** (`SubscriptionMetrics`): **Lunas bulan ini** (SUM `amount` tagihan lunas menurut `paid_at`, bulan di `POS_DEFAULT_TIMEZONE`) + tren vs bulan lalu + grafik 6 bulan; **Tagihan menunggu** (jumlah & nominal, tautan ke Tagihan berfilter); **Estimasi MRR** (tenant aktif, berpaket & bersiklus, masa aktif belum habis: harga bulanan atau tahunan ÷ 12 dari harga paket saat ini); **Bisnis berlangganan** per paket + jumlah trial. Tanpa polling. | Diterima | ADR 0024 |
| Q113 | Katalog fitur, kuota, add-on | ADR 0025. Katalog = tabel master `features` (dikelola langsung di database, tanpa CRUD; 6 lama + `advanced_reports`, `report_export`, `web_cashier`, `online_payment` di A2; paket lama otomatis diberi 4 fitur ini (dulu gratis; `online_payment` hanya bila punya `self_order`); tab Diskon & void tetap dasar), dicek di dashboard, mesin, dan **API (middleware `feature:*`, `FEATURE_NOT_AVAILABLE`)**; `auth/me` mengirim `tenant.features` & `tenant.limits`. Kuota = tabel master `quotas` (`outlets`, `users`, `devices`, `products` tanpa bahan baku, `members`; dikelola di database) di `plans.limits` + `tenants.limit_overrides` (menggantikan `max_outlets`; menerapkan paket menghapus batas khusus); lewat batas setelah downgrade: data lama jalan, tambah/aktifkan ditolak (`PLAN_LIMIT_REACHED`); pemakaian vs batas di Langganan owner, detail bisnis admin, `auth/me` `tenant.limits`, dan banner owner bila melebihi batas. Add-on (`feature` / `quota` ber-unit) mengikuti masa aktif paket, tagihan berbaris (`subscription_invoice_items`), tambah di tengah periode = tagihan `addon` prorata, berhenti di akhir masa aktif. Add-on dikelola admin (Pelanggan → Add-on, CRUD) & dipasang per bisnis lewat **Atur add-on** (tanpa tagihan); fitur efektif = paket ∪ add-on, batas = paket + Σ(unit × tambahan) (paket tanpa batas tetap tanpa batas). Tagihan berbaris: perpanjangan = paket + add-on aktif; **Tambah add-on** (owner, atau admin **Tagih add-on**) = tagihan `addon` prorata sisa masa aktif, aktif saat lunas; **Berhenti** = berlaku sampai akhir masa aktif lalu dilepas `pos:apply-plan-changes`. Tahap A1→A2→B1→B2→C1→C2. | Diterima | ADR 0025 |
| Q114 | Dasbor admin | Halaman Dasbor /admin sendiri (`App\Filament\Admin\Pages\Dashboard`): angka lintas tenant di-cache 10 menit (`DashboardCache`, driver database tanpa tag → generasi di nama key), tombol **Muat ulang data**, subjudul waktu data. D1: KPI *Bisnis pelanggan* (berbayar aktif, trial + berakhir ≤ 7 hari, bisnis baru 30 hari vs sebelumnya, konversi trial 90 hari = bisnis baru yang pernah lunas, hanya-baca/ditangguhkan, calon pelanggan baru), widget *Langganan* (Q112), chart batang pendapatan & bisnis baru 12 bulan (satu seri primary; palet kategori proyek gagal cek buta warna hijau↔kuning sehingga dasbor admin tidak memakai chart multi-kategori). D2: bar horizontal bisnis per paket (tanpa ditangguhkan) & per jenis usaha; tabel ringkas (maks. 6 baris, tanpa cache, tautan *Lihat semua*): segera berakhir 14 hari (tanpa ditangguhkan, terdekat dulu), tagihan menunggu lintas bisnis (jatuh tempo terdekat), pendaftaran terbaru, calon pelanggan belum dihubungi (terlama dulu, merah bila > 2 hari). D3 (`PlatformActivity`, agregat SQL dengan tanggal lokal ber-offset tetap — zona Indonesia tanpa DST; indeks `orders (status, completed_at)` & `(tenant_id, status, completed_at)`): KPI *Aktivitas platform* 30 hari (transaksi & nilai transaksi selesai sebelum retur dengan tren vs 30 hari sebelumnya, bisnis bertransaksi, perangkat aktif 7 hari + jumlah Android di bawah versi minimum), area chart nilai transaksi harian, tabel bisnis paling aktif, tabel bisnis aktif berumur ≥ 14 hari tanpa transaksi 14 hari (belum pernah dulu), bar versi Android perangkat aktif 30 hari (disembunyikan bila kosong). D4 (`SystemHealth`): kartu *Kesehatan sistem* — cron/scheduler (heartbeat `scheduler-heartbeat` tiap menit di cache; sehat bila ≤ 3 menit), antrean menunggu/diproses/terjadwal + umur tertua, job gagal (24 jam), backup terakhir (segar ≤ 26 jam), error log 24 jam (level error ke atas, file log terbaru), mode perawatan, lingkungan (PHP/Laravel, env, **APP_DEBUG aktif di production = merah**, driver queue/mail), ruang disk (merah < 10%; `disk_*_space` bisa dimatikan hosting). Tabel **Job di antrean** (`QueuedJobsWidget`, juga di Sistem → Queue): nama job berbahasa Indonesia dari kelas di payload (notifikasi dari command), status Diproses/Menunggu/Terjadwal, percobaan, masuk, mulai/jadwal; maks. 20, tanpa polling (tombol Muat ulang); isi data job tidak pernah ditampilkan. Langganan/pertumbuhan hanya SaaS. | Diterima | — |
| Q115 | Kasir menandai menu habis | Role **kasir** juga mendapat `product.toggle_available` (sebelumnya supervisor ke atas): kasir yang paling tahu stok di depan bisa menandai menu **habis/tersedia** di outlet sendiri (API `PATCH /products/{id}/availability`, tile kasir web tombol **Habis / Tersedia lagi**). Tetap ditolak saat bisnis hanya-baca (ADR 0009). | Diterima | — |
| Q116 | Perangkat & shift tenant di /admin | Visibilitas support tanpa akses DB (shift kasir web bisa tertinggal terbuka bila cookie browser hilang; data lama sebelum perbaikan fd99777). Detail bisnis (**Pelanggan → Tenant → lihat**) punya dua tab relation manager **hanya baca** (tanpa aksi; tidak tampil di form Ubah; `allTenants()` pada relasi tenant): **Perangkat** — nama, outlet, jenis (Android/Kasir web), versi aplikasi, terakhir aktif, status Aktif/Dicabut, ada shift terbuka; **Shift terbuka** — outlet, perangkat, dibuka oleh, sejak kapan (terlama dulu), merah + "Lebih dari 24 jam" bila dibuka > 24 jam (`PlatformActivity::STALE_SHIFT_HOURS`). Admin **tidak** menutup shift tenant; owner/supervisor menutupnya dari dashboard. Dasbor /admin (Q114): tabel ringkas **Shift terbuka > 24 jam (N)** lintas bisnis (`PlatformActivity::staleShifts`, di-cache `DashboardCache`, bisnis terhapus tidak dihitung, maks. 6 terlama dulu, baris bertaut ke detail bisnis, tanpa *Lihat semua*). | Diterima | — |
| Q117 | Device kasir web menganggur | Cookie browser hilang tanpa shift web terbuka membuat device web baru (Q54); device lama tetap dihitung kuota `devices` (ADR 0025) dan batas `POS_WEB_CASHIER_MAX_DEVICES`. **Menganggur** = `platform = web`, belum dicabut, tanpa shift terbuka, aktivitas terakhir (`last_seen_at`, atau `created_at` bila belum pernah dipakai) lebih dari `POS_WEB_CASHIER_IDLE_DAYS` hari (bawaan 30). `pos:prune-web-devices` harian 03.15 WIB (lintas tenant, aman diulang) **mencabut & melepas** device menganggur: `revoked_at` diisi, token perangkat & token user terikat dihapus, `device_uid` diganti `web-released:{id}` sehingga browser lama yang kembali mendapat device baru (bukan pesan "dicabut owner"); riwayat shift/transaksi tetap pada record lama, device itu tidak bisa **Aktifkan lagi**. Saat mendaftar browser baru dan batas per outlet penuh, device menganggur tertua **di outlet itu** dilepas; kuota paket penuh (`PLAN_LIMIT_REACHED`), device menganggur tertua **di bisnis itu** dilepas — bila masih lewat batas (mis. setelah downgrade) tetap ditolak dan tidak ada yang dilepas. Shift terbuka tidak pernah disentuh; cabut manual owner tetap memblokir browser itu. Dashboard **Pengaturan → Perangkat**: status **Menganggur** (abu-abu, tooltip), filter **Menganggur**, aksi massal **Cabut** (perangkat dengan shift terbuka dilewati & dilaporkan). | Diterima | ADR 0025 |
| Q118 | Periode paket (promo) | `plans.available_from` / `available_until` (UTC, opsional; kosong = tanpa batas waktu). Di luar periode paket **tidak bisa dipilih owner** untuk tagihan baru dan tidak tampil di Pengaturan → Langganan, kecuali paket itu milik bisnisnya sendiri (perpanjangan tetap boleh). Admin tetap bisa menagihkan paket aktif apa pun. Status periode admin: Tanpa batas waktu · Belum mulai · Sedang berjalan · Sudah berakhir. Daftar Paket: kolom **Kode** terpisah, pencarian kode/nama, tab Semua/Aktif/Nonaktif, filter Tampil/Periode/Memuat fitur/Dipakai bisnis, aksi Nonaktifkan/Aktifkan (`SetPlanActive`; bisnis yang memakai paket tetap berjalan). | Diterima | ADR 0024 |
| Q119 | Tagihan manual admin | Jenis tagihan `manual` (`CreateManualInvoice`, Tagihan → **+ Tambah**): bisnis, judul, jatuh tempo (akhir hari WIB), baris **biaya lain** (`custom`, bebas: instalasi, pelatihan, perangkat) atau **add-on** dengan harga khusus, diskon nominal, PPN % dari (subtotal − diskon) dibulatkan ke rupiah half-up (`ManualInvoiceTotals`), catatan untuk pelanggan (`memo`), opsi email owner. Harga & diskon **rupiah penuh** (tanpa sen: Midtrans menerima nominal bulat), total ≥ Rp1, diskon ≤ subtotal, maks. 30 baris; jatuh tempo ≥ hari ini **WIB**; add-on fitur 1 unit, belum termasuk paket/aktif, tidak di baris lain dan tidak di tagihan menunggu lain (tagihan add-on owner juga menolak add-on fitur yang ada di tagihan manual menunggu). **Tandai lunas** admin hanya untuk tagihan menunggu/kedaluwarsa (bukan dibatalkan). **Tidak membatalkan** tagihan menunggu lain (tagihan paket/add-on juga tidak membatalkan tagihan manual); owner bisa melihat & membayar beberapa tagihan menunggu. Lunas: baris add-on dipasang (`TenantAddons::attachFromInvoice`), paket/status/masa aktif tetap, periode kosong; bisnis tanpa paket boleh ditagih (`plan_id` nullable). Daftar Tagihan: tab per status + **Lewat jatuh tempo**, filter status/bisnis/jenis/paket/siklus/dibayar via/dibuat oleh/lewat jatuh tempo/tanggal dibuat/jatuh tempo/lunas/total, detail modal (baris, diskon, PPN, catatan). | Diterima | ADR 0024 |
| Q120 | Setelan pendaftaran lanjutan | Sistem → Pendaftaran: ringkasan (status, pendaftar hari ini/7/30 hari, trial berjalan, konversi 90 hari) + pendaftar mandiri terbaru (`tenants.created_by` kosong). Setelan baru (`system_settings`, nilai kosong = dihapus/bawaan): **paket selama trial** (hanya paket aktif & publik; fitur & kuota paket berlaku sejak daftar; kosong = semua fitur), **batas pendaftar per hari** WIB (tenant daftar mandiri selalu tanpa pembuat, termasuk yang sudah dihapus; 0 = tanpa batas; penuh → `TOO_MANY_REQUESTS`, form kembali dengan pesan), **domain email diblokir** (termasuk subdomain), **email notifikasi tim** (maks. 10, `TenantSignedUp` lewat antrean), **pesan saat ditutup** (tampil di /register). | Diterima | ADR 0009 |
| Q121 | Halaman Keamanan admin | Sistem → Keamanan: kartu ringkas (wajib 2FA, 2FA akun sendiri, jumlah admin ber-2FA, hasil pemeriksaan), **pemeriksaan konfigurasi** (`SecurityChecklist`: APP_DEBUG mati, APP_ENV production, HTTPS, cookie sesi secure, sesi ≤ 120 menit, wajib 2FA, semua admin ber-2FA — beserta langkah perbaikan), perlindungan bawaan, dan daftar super admin (2FA, login terakhir). Hanya membaca, tidak mengubah konfigurasi. | Diterima | — |
| Q122 | Halaman masuk & Turnstile | Semua halaman masuk (login owner gspos.id/login & panel dashboard satu domain, daftar /register — tanpa panel brand, lupa/atur kata sandi, kasir web, login super admin) memakai kerangka sama (`x-auth.layout` / `filament.shared.auth-layout`): latar navy brand, panel brand per aplikasi, kartu putih, field 44px berikon, tombol mata kata sandi tanpa border, versi `gs.POS {aplikasi} · v{APP_VERSION}` di bawah kartu. **Cloudflare Turnstile** (`App\Services\Security\Turnstile`) di bawah form, on/off **terpisah** untuk login super admin, login owner (+ daftar & lupa kata sandi), dan login kasir web di Sistem → Keamanan (konfirmasi kata sandi); bawaan mati. **Kunci** (site key + secret key) diisi di modal yang sama — secret disimpan terenkripsi (APP_KEY), tidak pernah ditampilkan ulang, dan diuji ke Cloudflare sebelum disimpan; `.env` `TURNSTILE_*` hanya cadangan. Modal menampilkan hostname yang harus didaftarkan di widget Cloudflare (gspos.id, app., admin.). Tanpa kunci Turnstile tidak bisa diaktifkan. Verifikasi server-side (`siteverify`, timeout 5 dtk) **fail-closed**; token sekali pakai di-reset setelah percobaan gagal; langkah kode 2FA tidak meminta Turnstile ulang. Error `VALIDATION_ERROR` pada field `cf-turnstile-response` / `data.turnstile`. | Diterima | — |
| Q123 | Bayar tagihan langganan | Owner Pengaturan → Langganan → **Bayar sekarang** memilih metode (`subscription_invoices.payment_method`): **QRIS** / **Transfer bank (VA)** = Snap Midtrans vendor dengan kanal sesuai metode (hanya bila key mode aktif tersedia; ganti metode = transaksi Snap lama dibatalkan). Midtrans vendor punya **2 mode**: Sandbox (dev) & Produksi — mode aktif + server key per mode diatur di Sistem → Pembayaran langganan (terenkripsi, format `SB-Mid-server-`/`Mid-server-` dicek, diuji ke API Midtrans sebelum disimpan); cadangan `.env` `POS_BILLING_MIDTRANS_SANDBOX_SERVER_KEY` / `POS_BILLING_MIDTRANS_PRODUCTION_SERVER_KEY` (lama: `POS_BILLING_MIDTRANS_SERVER_KEY` untuk mode bawaan `POS_BILLING_MIDTRANS_PRODUCTION`); webhook memverifikasi tanda tangan dengan key mode aktif, **Transfer manual** = rekening & QRIS statis dari admin Sistem → **Pembayaran langganan** + unggah bukti (gambar/PDF ≤ 2 MB, disk privat `billing-proofs/{tenant}`, nama pengirim wajib). Bukti terkirim = status tampil *Menunggu verifikasi*: tidak kedaluwarsa, tidak bisa dibayar online/kirim ulang, tagihan paket/add-on baru ditolak (tagihan tidak ikut dibatalkan). Admin Pelanggan → Tagihan tab **Perlu verifikasi**: lihat bukti (URL bertanda tangan 15 menit) lalu **Tandai lunas** atau **Tolak bukti** (alasan wajib, tampil ke owner; jatuh tempo diperpanjang bila sudah lewat). Detail tagihan bergaya dokumen (penerbit `POS_BILLING_ISSUER_*`, penerima, rincian, total, alur pembayaran). | Diterima | ADR 0024 |
| Q124 | Master bank | Tabel `banks` (kode unik, nama, singkatan, warna merek & teks, logo opsional PNG/JPG/WEBP ≤ 512 KB di disk public `banks/`, aktif, urutan); 12 bank umum diisi migrasi. Admin Sistem → **Bank** (tambah/ubah/nonaktif, tanpa hapus). Logo bawaan = SVG vektor dari singkatan & warna (`x-bank-logo`); SVG unggahan ditolak (bisa memuat skrip). Rekening Pembayaran langganan memilih bank dari master; owner melihat logo + tombol ikon salin nomor. | Diterima | — |
| Q125 | Rapikan Outlet & Riwayat perubahan | Pengaturan → Outlet mengikuti konvensi tabel (kode mono, kolom Alamat sendiri, zona waktu opsional, paginasi + footer). Riwayat perubahan: kolom **Kolom · Sebelum · Sesudah** terpisah dan sejajar per baris (maks. 3, sisanya di Detail), pelaku + role, tab Semua · Dibuat · Diubah · Menu habis/tersedia · Dihapus, klik baris = **Detail** (waktu lengkap, pelaku, data, tabel sebelum → sesudah nilai penuh, ID log). | Diterima | Q58 |
| Q126 | Dokumen & email pembayaran | **PDF** satu template A4 (`InvoicePdf`, dompdf, font Plus Jakarta Sans): sebelum lunas = *Tagihan* (rincian, total, terbilang, jatuh tempo, cara bayar + rekening), setelah lunas = *Kuitansi* (cap LUNAS, metode, waktu & referensi bayar); nama berkas `tagihan-{nomor}.pdf` / `kuitansi-{nomor}.pdf`, `Cache-Control: private, no-store`. Unduh: owner (Langganan, riwayat & detail, scope tenant) dan admin (Tagihan → ⋯ & detail). **Email** lewat antrean setelah commit: bukti transfer dikirim → semua super admin (*Verifikasi pembayaran*); bukti ditolak → owner aktif (alasan + jatuh tempo); lunas → owner aktif sekali (kuitansi PDF terlampir). Template email bermerek gs.POS (kop, Salam Tim gs.POS, footer & teks bantuan Bahasa Indonesia, tombol warna primary) untuk semua email aplikasi. | Diterima | Q123 |
| Q127 | Riwayat perubahan admin & bersih bukti | **Sistem → Riwayat perubahan** (/admin): setiap `SystemSettings::set` yang mengubah nilai dicatat ke activity log tanpa tenant (`log_name = platform`, kunci, kelompok menu, nilai lama → baru; array dibandingkan tanpa urutan kunci) dan master **Bank** (LogsChanges, pelaku admin); secret Turnstile & server key Midtrans hanya `••••` (tidak pernah disimpan). Tabel: cari, filter tanggal/menu/admin, Detail. Tidak terlihat di riwayat perubahan bisnis (tenant scope). `pos:prune-payment-proofs` harian 03.45 WIB: file bukti tagihan **batal/kedaluwarsa** lebih dari `POS_BILLING_PROOF_RETENTION_DAYS` (90) hari dihapus & `proof_path` dikosongkan; file yatim di `billing-proofs/` > 1 hari dihapus; bukti tagihan lunas/menunggu tidak pernah dihapus. | Diterima | Q123 |
| Q128 | Rapikan UI & tampilan per bisnis | (1) Beranda & Laporan menampilkan **rupiah bulat** (`Money::formatWhole`); detail transaksi, struk, export tetap rinci. (2) Panduan **Mulai berjualan** hanya untuk bisnis baru: hilang bila semua langkah selesai, ada transaksi selesai, atau disembunyikan owner (`tenants.preferences.setup_dismissed`). (3) Panel dashboard & admin **bawaan terang** (`defaultThemeMode`), pilihan pengguna di browser tetap diutamakan; halaman situs selalu terang. (4) Toast seragam (aksen status, ikon, di bawah topbar), judul objek + kata kerja tanpa "berhasil". (5) Subjudul ringkas satu baris. (6) HP/tablet: subjudul 13px, filter 2 kolom, badge tidak terpotong, kolom sekunder disembunyikan. (7) Halaman error senada halaman masuk, **kode error besar**. Audit: semua kartu form owner berikon, uang = `MoneyInput`, tab status dokumen stok/PO/promo (`StatusTabs::forEnum`), menu admin Sistem berurutan & berbahasa Indonesia (Antrean, Pemeliharaan). **Pengaturan → Bisnis & tampilan** (izin `business.settings`, owner): nama & jenis usaha (kode bisnis tetap), logo sidebar (PNG/JPG/WEBP ≤ 1 MB, `public/tenants/{id}`), **warna utama** 8 preset atau hex bebas (wajib kontras ≥ 4.5:1 dengan teks putih; palet 50–950 berpatokan warna di shade 600), **gaya sidebar** berwarna/terang, **mode bawaan** terang/gelap/ikuti perangkat, tampilkan lagi panduan; disimpan di `tenants.preferences` (JSON), diterapkan per request (`ApplyTenantAppearance` + `TenantAppearance::css`), mode gelap tetap memakai sidebar navy gelap. | Diterima | — |
| Q129 | Opsi bawaan & dialog kasir | Setiap opsi punya **`is_default`** (diatur owner di form grup opsi, kolom *Bawaan*; API `options[].is_default`). Jumlah bawaan maks. `max_select` (pilih-satu = 1 bawaan, selain itu `VALIDATION_ERROR`). Pilihan awal saat produk dibuka (kasir web, self-order; aplikasi memakai `is_default` dari katalog): opsi bawaan yang tersedia → grup **wajib** (`min_select ≥ 1`) tanpa bawaan = opsi tersedia pertama → grup opsional tanpa bawaan kosong; opsi habis tidak pernah terpilih otomatis. Migrasi mengisi bawaan lama: grup pilih-satu wajib = opsi pertama, opsional = opsi gratis pertama (perilaku sebelumnya tetap). Grup seperti Gula/Level es sebaiknya **wajib dengan bawaan Normal**; Tambahan tetap opsional. Kasir web: field diskon item berformat rupiah tanpa spinner & satu garis fokus; dialog **Pembayaran berhasil** berisi nomor & waktu, kotak kembalian besar, total, metode (tunai = uang diterima), jenis/meja, jumlah item, member, tombol Cetak struk & Transaksi baru (Enter). | Diterima | Q43 |
| Q131 | Rapikan halaman kasir web | Semua halaman kasir web selain Transaksi (Riwayat & detail, Retur, Shift & rekap, Kasbon, Pesanan meja) memakai satu sistem visual dengan layar Transaksi: kelas bersama `.pos-page`/`.pos-page-head` (judul + subjudul satu baris, aksi berikon di kanan), `.pos-stats`, `.pos-card`, `.pos-table` (header abu kecil, baris 48px, angka rata kanan tabular, aksi ikon 32px, kolom sekunder disembunyikan ≤ 900px), `.pos-badge` (status seragam), `.pos-empty` (ikon + judul + kalimat + aksi), `.pos-field`/`.pos-select`/`.pos-check`; **semua kontrol 40px (`--pos-btn-h`), radius `--pos-radius`, satu garis fokus**. Nominal uang = `<x-cashier.money>` (tampil `25.000`, dikirim angka murni lewat hidden input dengan **nama field lama**, tanpa Alpine). Qty (pecah bill, retur) = stepper `− n +`. Aksi berisiko (Lunasi, Void, Gabung, Catat retur, Tutup shift) memakai dialog konfirmasi yang sama dengan Kunci/Keluar; tutup shift menampilkan selisih langsung (hijau pas, kuning lebih, merah kurang). Riwayat: statistik shift (transaksi, omzet, rata-rata, void — tidak ikut filter), tab status, cari nomor/meja, baris bisa diklik. Kasbon: statistik member & total, kolom Member/Kode/No. HP (disamarkan)/Kasbon/Pelunasan (rata kanan) + tombol **Penuh**. Detail transaksi bergaya *Pembayaran berhasil* (uang diterima & kembalian). Pesanan meja: dua kolom berbadge jumlah, chip meja, tolak = alasan inline. Tanpa perubahan alur bisnis, route, nama field, atau izin. Commit: `7b9297b`, `11ec4d8`, `ffcc16d` (tertulis "Q130" di pesan commit; nomor itu dipakai keputusan menu owner) dan lanjutannya. | Diterima | Q54, Q129 |
| Q130 | Kelengkapan menu owner | (1) **Stok → Riwayat stok** (`stock.adjust`): kartu stok hanya baca, tab Semua/Masuk/Keluar/Penyesuaian & opname, filter tanggal (bawaan hari ini)/produk/jenis/oleh, kolom Dokumen menautkan order, retur, stok masuk, opname, transfer; dibuka dari aksi *Riwayat stok* di Produk (`?product=` → filter produk tanpa batas tanggal). (2) **Pelanggan → Kasbon** (`member.view`; pelunasan & batas `member.manage`, Action sama dengan menu Member): tab Semua/Belum lunas/Lama (≥ 30 hari)/Melebihi batas/Lunas; umur = sejak saldo terakhir naik dari nol (pelunasan sebagian tidak mengubahnya); menu tampil bila kasbon aktif atau masih ada sisa kasbon. (3) **Transaksi → Pesanan meja** (`order.view_all`, fitur `self_order`): hanya baca. (4) Penjualan tab **Diretur** (`refunded_total > 0`, tanggal = tanggal order). (5) Laporan tab **Pembelian** dipisah dari Stok (fitur `stock`; API `reports/purchases` ikut tab ini; export Excel/PDF per tab). (6) **Lonceng peringatan** di topbar (`DashboardAlerts`), dihitung saat halaman dimuat; *perlu refund manual* tidak masuk karena belum ada penanda selesai. | Diterima | `docs/standards/ui/layout.md`, `docs/standards/ui/components/table.md` |
| Q132 | Susunan menu owner | Laporan tanpa grup tepat di bawah Beranda (grup 1-menu dihapus); Transaksi: Kasir · Penjualan · Pesanan meja · Shift; Stok mengikuti alur Purchase order → Stok masuk → Transfer → Stok opname → Riwayat stok → Supplier; Program member pindah ke Pelanggan; Pengaturan hanya setelan toko; grup baru **Akun** (Langganan, Riwayat perubahan); ikon unik per menu (Voucher `gift`, Program member `star`, Purchase order `shopping-cart`, Langganan `rocket-launch`). Slug/URL tetap. | Diterima | `docs/standards/ui/layout.md` §2 |
| Q26 | Diskon produk | Belum ada promo/harga coret di level produk; diskon tetap lewat kasir (item & order, batas role + PIN, Q18). Promo produk masuk backlog. | Diterima | — |
| Q23 | Pengakuan omzet | Laporan memakai `completed_at` (order selesai); void dilaporkan terpisah berdasarkan `voided_at`; rentang tanggal lokal outlet, maks. 366 hari. | Diterima | `docs/api/report.md` |
