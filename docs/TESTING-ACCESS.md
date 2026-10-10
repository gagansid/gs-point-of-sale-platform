# Akses untuk uji manual (lokal)

> Hanya untuk development lokal. Data dari `php artisan db:seed` (`DemoTenantSeeder`, ditolak di
> production). **Jangan** jalankan `migrate:fresh` / `db:wipe`: database lokal ikut terhapus.

## URL (mode subdomain, `.env` lokal)

| Bagian | URL | Login |
|---|---|---|
| Halaman depan | http://gspos.localhost:8000 | — |
| Login pelanggan (pusat) | http://gspos.localhost:8000/login | owner / manager → dialihkan ke dashboard |
| Daftar mandiri | http://gspos.localhost:8000/register | buat bisnis trial baru |
| Lupa kata sandi | http://gspos.localhost:8000/forgot-password | — |
| Dashboard pelanggan | http://app.gspos.localhost:8000 | lewat login pusat |
| Panel super admin | http://admin.gspos.localhost:8000/login | super admin |
| API | http://api.gspos.localhost:8000/v1 | token (lihat `docs/api/auth.md`) |
| Dokumentasi API (OpenAPI) | http://api.gspos.localhost:8000/docs/api | — |

Tanpa subdomain (domain `POS_*` kosong): `/dashboard`, `/admin`, `/api/v1`.

### Halaman dashboard penting

| Menu | Path |
|---|---|
| Beranda | `/` |
| Penjualan · Shift | `/orders` · `/shifts` |
| Produk · Kategori · Grup opsi | `/products` · `/categories` · `/option-groups` |
| Laporan | `/reports` |
| Pengaturan → Outlet · Profil outlet | `/settings/outlets` · `/settings/outlet?outlet={id}` |
| Karyawan · Metode pembayaran | `/settings/employees` · `/settings/payment-methods` |
| Pilih outlet (sidebar, di bawah logo) | `POST /switch-outlet/{id|all}` |

## Akun demo (bisnis "Kopi Senja (Demo)", outlet `DEMO01`)

| Role | Login | Kata sandi / PIN | Bisa |
|---|---|---|---|
| Super admin | `admin@demo.test` | `password` | Panel admin (tenant, lead, pendaftaran) |
| Owner | `owner@demo.test` | `password` | Semua menu dashboard, semua outlet |
| Manager | `manager@demo.test` | `password` | Dashboard: produk, stok, laporan, transaksi (outlet yang ditugaskan) |
| Supervisor | Sari (pilih nama di tablet) · kasir web `sari` · `sari@demo.test` | PIN `222222` · kata sandi `password` | Kasir + approval void/diskon; tidak bisa dashboard |
| Kasir | Budi (pilih nama di tablet) · kasir web `budi` · `budi@demo.test` | PIN `123456` · kata sandi `password` | Transaksi di tablet; tidak bisa dashboard |

Perangkat demo: `device_uid` `demo-device-01` ("Kasir Depan").

## Ringkasan permission per role

Sumber resmi: tabel permission di `docs/SPEC.md` dan `App\Enums\UserRole::permissions()`.

| Role | Login | Akses utama |
|---|---|---|
| Owner | email + kata sandi | `*` (termasuk `outlet.manage`, `outlet.access_all`, `user.manage`) |
| Manager | email + kata sandi | `order.*`, `shift.*`, `product.*`, `stock.*`, `report.*` |
| Supervisor | PIN (tablet), username + kata sandi (kasir web, nanti) | `order.*`, `shift.*`, `product.toggle_available` |
| Kasir | PIN (tablet), username + kata sandi (kasir web, nanti) | buat order, lihat order sendiri, cetak ulang, diskon dalam batas, shift sendiri |

## Skenario uji yang disarankan

- Owner: tambah outlet ke-2 → pindah outlet di pemilih outlet sidebar → cek stok produk, laporan, penjualan berubah.
- Owner: tugaskan Budi ke outlet ke-2 di Karyawan → Budi tidak muncul di layar PIN perangkat `DEMO01`.
- Admin: ubah "Maksimal outlet aktif" bisnis → tambah outlet melebihi batas ditolak.
- Bisnis hanya-baca: set "Langganan berakhir" ke kemarin di admin → dashboard tampil banner, simpan ditolak.

### Menu & metode bayar per outlet (ADR 0011)

Butuh minimal 2 outlet aktif (demo: Kopi Senja Kemang & Kopi Senja Cilandak).

- Owner: Produk → Ubah → hapus centang satu outlet di "Dijual di outlet" → pilih outlet itu di pemilih outlet sidebar →
  daftar produk menampilkan badge "Tidak dijual"; filter "Dijual di outlet ini" berfungsi.
- Manager (satu outlet): form produk hanya menampilkan outletnya; outlet lain tidak berubah saat disimpan.
- Grup opsi → menu baris "Opsi habis" → centang Large → badge Large merah (hanya di outlet yang dipilih).
- Metode pembayaran → Ubah QRIS → hapus centang satu outlet → kolom Outlet "1 dari 2 outlet" (tooltip
  menyebut outletnya). Tunai tidak punya pilihan outlet.
- Outlet → Tambah → "Salin menu & metode bayar dari" outlet tadi → produk yang tidak dijual & QRIS nonaktif
  ikut tersalin; stok dan opsi habis tidak.
- API (perangkat outlet itu): `/catalog` tidak berisi produk tidak dijual & QRIS; `options[].is_available`
  false untuk Large; checkout dengan produk/opsi/metode tersebut → `422 VALIDATION_ERROR` per field.
- Selesai uji: kembalikan centang semua outlet agar data demo seperti semula.

### Email & verifikasi karyawan (SPEC Q46)

- Karyawan → Tambah kasir tanpa email → ditolak "wajib diisi".
- Tambah kasir dengan email → kolom Email "Belum verifikasi"; email terkirim (lokal: lihat `storage/logs/laravel.log`
  bila `MAIL_MAILER=log`). Buka linknya → halaman "Email terverifikasi" tanpa tombol dashboard (kasir).
- Menu baris "Kirim ulang verifikasi": ke-4 kalinya dalam 10 menit ditolak.
- Dengan 2+ outlet: form karyawan menampilkan daftar pilih outlet (cari, inisial, kode, "(n dipilih)"); 1 outlet = tidak tampil.
