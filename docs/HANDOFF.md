# Catatan Lanjut Pengerjaan

> Diperbarui: 2026-10-11 · Branch: `feature/pos`
> Status: **Beranda berfilter + modul Laporan lengkap (SPEC Q49–Q52, ADR 0012) + perapian UI selesai & di-push** (`8c0fedc`).
> Kualitas: 872 test lulus (SQLite; test laporan juga MySQL `gspos_test`), Pint & Larastan level 6 bersih.
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

### Beranda & modul Laporan (SPEC Q49–Q52, ADR 0012) — selesai, di-commit
| Bagian | Isi |
|---|---|
| Fondasi | `ReportPeriod` (preset minggu/tahun, granularitas jam/hari/minggu/bulan, batas 31 hari), `ReportFilters` (+ kategori, produk, shift), `ReportContext` (satu paket parameter + batas supervisor), snapshot `order_items.category_name` |
| Dashboard | Beranda & Laporan berbagi `InteractsWithReportFilters` (URL ringkas `?period=&from=&to=&compare=&type=&group=`, `?tab=`); Laporan 12 tab (`ReportTab`), widget per tab, tabel dari data teragregasi |
| Akses | Supervisor masuk panel **hanya Laporan** (tab operasional, maks. 31 hari dipotong di server); Penjualan/Shift/cetak ulang struk butuh `UserRole::hasFullDashboard()`; kasir: rekap shift sendiri via API |
| API | `trend`, `categories`, `options`, `employees`, `shifts`, `shifts/{id}` (+ `/pdf`), `heatmap`, `stock` — izin per endpoint dari `ReportTab` (`docs/api/report.md`) |
| Export | Grup Export: Excel/PDF tab ini atau lengkap, sinkron, batas 50.000/5.000 baris → "Persempit rentang tanggal"; dompdf tanpa PHP/remote/JS |

Catatan: PDF memakai DejaVu Sans sampai file `resources/fonts/PlusJakartaSans-Regular.ttf` & `-Bold.ttf` ditambahkan
(template otomatis memakainya). HPP/margin belum ada: `products.cost_price` sudah ada, tetapi belum di-snapshot ke
`order_items` (lihat rekomendasi R1).

### Perubahan terakhir — sesi 2026-10-11 (semua di-push)
| Commit | Isi |
|---|---|
| `0d12fe4` | Beranda: URL ringkas (`?period=…` hanya nilai bukan bawaan, `$filters` hanya di session), ikon kalender membuka popup, nominal KPI mengecil mengikuti lebar kartu, teks tren singkat ("Naik 12,5%") |
| `d7a1ad5` … `d496469` | Modul Laporan tahap 1–7 (tabel di atas) |
| `42a40c9` | Filter jadi toolbar ringkas (6 field satu baris di layar lebar), filter lanjutan di **panel samping** (badge jumlah + "Hapus filter"), KPI 2 → 4 kolom & lebih rapat |
| `6f42910` | Beranda: "Mulai berjualan" paling atas; **semua form**: input group tanpa garis pemisah ikon/label, DatePicker 32px; jarak antarblok/widget/kartu seragam 16px |
| `8c0fedc` | Tombol "Filter lanjutan" & tab tersegmentasi 32px (standar = tombol Simpan/Batal) |

Bug yang ditemukan & diperbaiki di sesi ini (semua ada test): tanggal rusak di URL membuat halaman error; download PDF
lewat Livewire gagal (kini stream); nomor halaman PDF "1 / 0"; filter lanjutan hilang saat form diisi ulang; nilai
enum tipe order dari form diabaikan; link berparameter sebagian memakai periode yang salah.

Standar UI yang kini berlaku (jaga konsistensinya): tinggi kontrol **32px** (`--control-h`) untuk field, tombol,
dan tab tersegmentasi; ukuran kecil hanya di dalam tabel (paginasi 28px, ikon aksi baris 26px); jarak antarblok 16px;
input group tanpa garis pemisah; tanpa `style=""`/hex di Blade (pakai kelas + token).

---

## 2. Belum dikerjakan

| Prioritas | Pekerjaan | Catatan |
|---|---|---|
| 0 | Tindak lanjut modul Laporan | Lihat tabel "Perlu diubah / dilanjutkan" di bawah |
| ✅ | Kasir web (SPEC Q54, W1–W6) | Selesai 2026-10-11: akses per karyawan, login `/cashier`, shift X/Z, layar transaksi, open bill, approval PIN, void, menu Perangkat. Sisa: uji di browser tablet sungguhan & printer thermal; cetak tanpa dialog di luar cakupan |
| ✅ | Minggu 7 — panel admin | Selesai 2026-10-11 (lihat PROGRESS); sisa OWASP ZAP & uji manual di staging |
| 3 | Minggu 8 — deploy | cPanel, uji MySQL ulang (fitur 9–10 Okt & migrasi multi-outlet), uji dengan Flutter, pilot |
| Ditunda | Import produk dari Excel | Ditunda atas keputusan pemilik produk (2026-10-10); rencana: lewat `SaveProduct`, cegah formula injection (`SafeCell`) |
| Ditunda | Aplikasi tablet | Fase tablet/aplikasi kasir (menu Perangkat sudah ada, Q54) |

### Perlu diubah / dilanjutkan (modul Laporan & UI)
| # | Pekerjaan | Kenapa | Catatan |
|---|---|---|---|
| L1 | Integrasi Flutter | Endpoint laporan baru & izin supervisor/kasir belum dipakai aplikasi | `docs/api/report.md`: supervisor ≤ 31 hari (422 di luar itu), kasir `shifts/{id}` + `/pdf` (X/Z-report) |
| L2 | Uji migrasi `category_name` & `unit_cost` di MySQL dengan data besar | Backfill `UPDATE` per kategori; perlu dicek waktu jalannya di hosting | Jalankan di salinan DB produksi sebelum deploy |
| L3 | Font PDF Plus Jakarta Sans | Kini DejaVu Sans | Tambahkan TTF (lisensi OFL) ke `resources/fonts/` — butuh persetujuan pemilik untuk mengunduh — ✅ Selesai 2026-10-11: TTF Regular/Bold + OFL.txt dari github.com/tokotype/PlusJakartaSans, subsetting aktif, cache `storage/fonts` |
| L4 | Uji batas export di hosting | dompdf & Excel sinkron memakai memori/waktu PHP shared hosting | Bila perlu turunkan `ReportExporter::MAX_PDF_ROWS` / `MAX_EXCEL_ROWS` |
| L5 | Total di footer tabel produk/kategori/opsi | Tabel array Filament tidak mendukung summarizer; total kini di deskripsi tabel | Opsional: `contentFooter()` khusus — ✅ Selesai (`2990a64`): baris total `<tfoot>` via `ReportTableWidget::totalRow()` |
| L6 | Seragamkan ukuran tersisa (opsional) | Paginasi 28px, ikon aksi baris 26px, tab garis bawah Penjualan/Shift 39px | Sengaja tidak diubah (standar tabel/navigasi) — tanyakan pemilik |
| L7 | Perbarui dokumentasi OpenAPI (Scramble) & `docs/api/README.md` (jumlah endpoint) | 9 endpoint laporan baru | — — ✅ Selesai (`a2a33bd`): 58 endpoint, Scramble tanpa masalah (`scramble:analyze`) |

### Rekomendasi tambahan
| # | Rekomendasi | Manfaat |
|---|---|---|
| R1 | Snapshot `cost_price` ke `order_items` saat checkout → laporan HPP & margin kotor per produk/kategori | Finance: laba kotor akurat walau harga modal berubah (`products.cost_price` sudah ada) — ✅ Snapshot selesai (`2a6a926`, Q53: `order_items.unit_cost`); laporan Laba kotor ✅ (tab + API `/reports/profit`) |
| R2 | Cache agregat laporan (driver `database`, 60 detik, kunci tenant + filter; periode yang sudah lewat lebih lama) — sekaligus menutup T1 | Beranda/Laporan lebih ringan di shared hosting; `daily`/`hourly`/`heatmap` kini mengelompokkan di PHP |
| R3 | Throttle aksi & endpoint export (mis. 10/menit per user) | Export sinkron cukup berat; cegah penyalahgunaan (DoS ringan) — ✅ Selesai (`bd473df`): `ExportThrottle`, `pos.rate_limits.export` |
| R4 | Wajib email terverifikasi untuk login dashboard supervisor | Supervisor kini bisa login web (Q50); verifikasi (Q46) belum memblokir akses — ✅ Selesai (`ae629f7`) |
| R5 | Ringkasan harian otomatis (email/WhatsApp) lewat cron cPanel `schedule:run` | Owner tahu omzet & anomali (void, selisih kas) tanpa membuka dashboard |
| R6 | Target omzet harian/bulanan per outlet + progres di Beranda | Manager punya patokan, bukan hanya pembanding periode lalu |
| R7 | Audit log (`spatie/laravel-activitylog`, Fase 2) terhubung ke panel Audit | Jejak perubahan harga, diskon, void lebih lengkap |
| R8 | Simpan preset filter per user ("Laporan mingguan outlet A") | Mempercepat rekap rutin |
| R9 | Tabel data pendamping grafik (aksesibilitas `chart.md`) & uji E2E browser untuk alur laporan | Mutu & aksesibilitas |

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
