# Catatan Lanjut Pengerjaan

> Diperbarui: 2026-10-10 · Branch: `feature/pos`
> Status: **multi-outlet (ADR 0010) dan menu per outlet (ADR 0011) selesai & di-commit.**
> Kualitas: 691 test lulus (SQLite & MySQL `gspos_test`), Pint & Larastan level 6 bersih.
> Rincian per tahap tetap di [`docs/PROGRESS.md`](PROGRESS.md); dokumen ini ringkasan untuk melanjutkan.

---

## 1. Sudah dikerjakan

### Fondasi (Minggu 1–6) — sudah di-commit
- Standar proyek, SPEC, ADR 0001–0010, CI, envelope API, tenant scope fail-closed, permission per role.
- Auth API: login email, device token, login PIN (kunci 15 menit), `/auth/me`, logout.
- Katalog: produk, kategori, grup opsi, ETag `/catalog`.
- Transaksi: shift, `OrderCalculator`, checkout, open bill, split payment, struk, void.
- Laporan: API, widget beranda, menu Penjualan/Shift/Laporan, export Excel.

### Dashboard & situs — sudah di-commit
- Gaya UI seragam: tabel, form, tab di card, filter lipat, URL bersih, font Plus Jakarta Sans.
- Katalog+: favorit, stok minimum/menipis, urutan kasir, status kategori/grup opsi.
- Subdomain `gspos.id` / `app.` / `admin.` / `api.` (ADR 0008); semua URL berbahasa Inggris.
- Onboarding (ADR 0009): daftar mandiri + trial, mode hanya-baca, verifikasi email, checklist +
  template menu, lead → tenant, lupa kata sandi, edisi jual putus + `pos:install`.
- Setelan: Profil outlet, Karyawan (PIN + username/kata sandi untuk kasir web), Metode pembayaran.

### Multi-outlet (ADR 0010, SPEC Q36–Q41) — selesai, di-commit (`be9f86c`)
| Langkah | Isi |
|---|---|
| M2 Data | Migrasi `2026_10_11_100000_add_multi_outlet`: `outlet_user`, `outlet_product` (stok, stok minimum, tersedia per outlet), `tenants.max_outlets`, `outlets.is_active`, `stock_movements.outlet_id`. Kolom stok di `products` & `users.outlet_id` dihapus, data lama dipindahkan |
| M3 Akses | `User::accessibleOutlets()` (owner = permission `outlet.access_all`), `OutletScope` fail-closed (order/shift/device → 404 di outlet lain), `CurrentOutlet` per request (outlet device / pilihan session), token di device outlet lain atau nonaktif → 403 |
| M4 Dashboard | Pengaturan → Outlet (`settings/outlets`: tambah, nonaktifkan, error `OUTLET_LIMIT_REACHED`), Profil outlet per outlet, pemilih outlet di topbar, pilihan outlet di form Karyawan, admin "Maksimal outlet aktif" |
| M5 Stok | `ProductStock` + `Product::atOutlet()`; `AdjustStock`, tandai habis, potong stok checkout, `/catalog`, `PinUsers` per outlet |
| M6 Laporan | Penjualan, Shift, Laporan, Beranda mengikuti outlet topbar; `GET /v1/outlets`; laporan API `?outlet_id=` (id atau `all`) |

Keputusan penting:
- Bisnis baru (SaaS) dibatasi **1 outlet aktif** (`POS_DEFAULT_MAX_OUTLETS`); bisnis hasil migrasi tanpa batas.
- Saat "Semua outlet" dipilih, halaman Produk menampilkan stok **outlet pertama** (ada keterangan di subjudul).
- Outlet tidak pernah dihapus; nonaktif = perangkatnya tidak bisa bertransaksi, laporan tetap ada.
- Dokumentasi sudah diperbarui: SPEC, `docs/api/{auth,settings,product,report,README}.md`, `docs/PROGRESS.md` §1c.

### Menu & metode bayar per outlet (ADR 0011, SPEC Q42–Q45) — selesai, di-commit
| Langkah | Isi |
|---|---|
| P1 Keputusan | Opsi B: katalog bersama, produk dipilih per outlet, **harga tetap sama** (harga per outlet di backlog); metode bayar cukup aktif/nonaktif per outlet; SPEC Q39 diganti |
| P2 Data | `outlet_product.is_listed`, `outlet_option`, `outlet_payment_method`. **Tanpa baris = dijual/tersedia/aktif** (sama dengan stok outlet), jadi data lama tidak disalin |
| P3 Action | `SetProductListing` (lewat `SaveProduct`, hanya outlet aktif yang dipegang user), `SetOptionAvailability`, `SetPaymentMethodAtOutlet` (tunai selalu aktif), `CreateOutlet` `copyMenuFrom` |
| P4 Dashboard | Form produk "Dijual di outlet", badge/filter "Tidak dijual", aksi "Opsi habis" (outlet topbar), metode bayar "Aktif di outlet" + kolom Outlet, tambah outlet "Salin menu & metode bayar dari" |
| P5 API | `/catalog` per outlet perangkat (+ `options[].is_available`, kategori kosong tidak dikirim); checkout/tambah bayar menolak dengan `VALIDATION_ERROR` per field |

Catatan untuk Flutter: field baru `options[].is_available`; kategori aktif tanpa produk di outlet tidak lagi dikirim.

---

## 2. Belum dikerjakan

| Prioritas | Pekerjaan | Catatan |
|---|---|---|
| 1 | Kasir web | Login: slug bisnis + username + kata sandi (SPEC Q35); cadangan bila tablet rusak; pakai outlet dari pilihan kasir |
| 2 | Minggu 7 — panel admin | Versi aplikasi, pengumuman, backup, log viewer, queue, rate limit & audit keamanan |
| 3 | Minggu 8 — deploy | cPanel, uji MySQL ulang (fitur 9–10 Okt & migrasi multi-outlet), uji dengan Flutter, pilot |
| Ditunda | Import produk dari Excel | Ditunda atas keputusan pemilik produk (2026-10-10); rencana: lewat `SaveProduct`, cegah formula injection (`SafeCell`) |
| Ditunda | Menu Perangkat (S4) & aplikasi tablet | Fase tablet/aplikasi kasir |

Utang teknis tersisa (lihat PROGRESS): T1 cache widget beranda, T2 gambar lama tidak terhapus,
T3 cetak struk dari web, T4 Node lokal, T5 rencana PHP 8.3 + Laravel 13, T6 `POS_ADMIN_2FA_REQUIRED=true` di production.

Ide lanjutan multi-outlet (opsional): stok "Semua outlet" sebagai total, transfer stok antar outlet,
filter Karyawan per outlet, test migrasi data multi-outlet di MySQL, harga & nama metode bayar per outlet (ADR 0011, ditunda).

---

## 3. Peringatan saat melanjutkan

- **Jangan** menjalankan `migrate:fresh`, `migrate:refresh`, `migrate:reset`, atau `db:wipe`, termasuk
  dengan `--env=testing`: `.env.testing` tidak ada, jadi perintah itu menghapus `database/database.sqlite`.
  Pada 2026-10-10 data lokal sempat terhapus; data demo sudah diisi ulang dengan `php artisan db:seed`.
- Test memakai SQLite `:memory:` dari `phpunit.xml`; PHPStan butuh `--memory-limit=1G`.
- Akun demo lokal: lihat komentar di `database/seeders/DemoTenantSeeder.php`.

```bash
php artisan test --parallel
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=1G
```
