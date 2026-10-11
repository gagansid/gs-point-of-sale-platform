# Catatan Lanjut Pengerjaan

> Diperbarui: 2026-10-11 · Branch: `feature/pos`
> Status: **Program member M1–M4 selesai** (ADR 0013); M5–M7 & self-order (ADR 0014) berikutnya. Deploy ditunda atas keputusan pemilik.
> Kualitas: 1087 test lulus (SQLite) + 3 uji E2E Dusk (`./vendor/bin/pest tests/Browser`), Pint & Larastan level 6 bersih.
> Test MySQL `gspos_test` belum dijalankan ulang untuk fitur 11 Okt (lihat L2).
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

### Sesi 2026-10-11 sore (SPEC Q55–Q59)
| Commit | Isi |
|---|---|
| `b1df171` | Menu **Kasir** di sidebar dashboard → `/cashier/confirm` (tab baru), masuk ulang dengan kata sandi atau PIN (Q55). PIN hanya di jalur ini; tanpa link lupa kata sandi/PIN |
| `dfdb65c` | Halaman masuk kasir bermerek (`x-cashier.auth`: latar navy, panel fitur, versi di bawah kartu), tab cara masuk di form (tanpa `?mode=`), pilih outlet > 3 = dropdown dengan pencarian, Keluar → kembali ke dashboard |
| `9a9487a` | Pesan kasir web jadi **toast kanan atas** (`PointOfSale::toast()` → event `cashier-toast`); hapus/lanjutkan transaksi tertahan ada pesannya; perbaikan total lama tertinggal setelah Tahan |
| `6a58a6d` | Item sama (produk + opsi) tanpa catatan & diskon item digabung ke satu baris |
| `e05a85c`, `3c18e86` | Semua halaman masuk (kasir, admin, Filament simple page, `gspos.id/login`) memakai field & tombol 40px; halaman lain tetap 32px |
| `8995ca5` | PIN sendiri di **Profil** (wajib kata sandi saat ini, reset kunci PIN, cabut token) |
| `0b9e64a` | **R6** target omzet harian/bulanan per outlet (Profil outlet & `PUT /v1/outlet`) + widget Beranda (Q56) |
| `27040e1` | **R5** ringkasan harian email `pos:daily-summary` (tiap jam, sekali per hari setelah 07.00 waktu outlet; toggle di Profil, bawaan owner) (Q57) |
| `9fedd51` | **R7** Riwayat perubahan (`spatie/laravel-activitylog`, `ActivityLog` per tenant, izin `audit.view`), tautan dari panel Audit; disimpan 365 hari (Q58) |
| `489e114` | **Import produk** dari Excel/CSV dengan template, validasi semua baris dulu, upsert per SKU (Q59) |
| `000d2f0` | **R9** uji E2E Dusk (kasir web, menu Kasir, dashboard) dengan `database/dusk.sqlite` terpisah |

Aturan untuk tim Flutter (repo `gs-point-of-sale-app`, tidak ada di mesin ini):
- **F1** — samakan gabung item keranjang: produk & pilihan opsi sama, tanpa catatan & tanpa diskon item → qty
  ditambah ke baris yang ada; beda opsi/catatan/diskon → baris baru (SPEC Q54).
- **F2** — `GET/PUT /v1/outlet` kini memuat `daily_sales_target` & `monthly_sales_target` (nullable, Q56); opsional
  ditampilkan di setelan outlet aplikasi.

### Program member (ADR 0013, SPEC Q60–Q67) — M1–M4 selesai 2026-10-11
| Commit | Isi |
|---|---|
| `0dd6186`, `6376280` | **M1** data member (HP +62 unik per bisnis, persetujuan, kode acak, hapus = anonimkan), Pengaturan → Program member (aktif, outlet ikut), Pelanggan → Member, API `/v1/members`, `member_id` di checkout/open bill, kolom cari member di kasir web (Enter/scanner) |
| `55d0f7a` | **M2** ledger poin append-only (FIFO, saldo cache + lock), poin saat order selesai (subtotal setelah diskon, tanpa pajak/service), void menarik poin, `pos:expire-member-points` harian, penyesuaian manual (`member.points_adjust`), API riwayat poin, struk |
| `5eb90f7` | **M3** metode bayar sistem **Poin** + bukti QR dinamis / PIN member (kunci 5×), `RedeemPoints` (kelipatan, minimal, maks. % tagihan, saldo), void mengembalikan poin, **diskon member %** di `OrderCalculator` (`MemberPricing`: urutan stempel → manual → voucher → member, tidak dihitung ke batas diskon kasir) |
| (M4) | **Halaman member** `gspos.id/m/{kode-bisnis}`: masuk HP + kata sandi, daftar mandiri, QR `KODEMEMBER-123456`, riwayat, profil, ganti kata sandi, hapus akun; kata sandi sementara dari dashboard; scan QR di kasir web memasang member + mengisi bukti |

**Berikutnya (lanjut dari sini):** M5 tier (spend 12 bulan bergulir, pengali poin, diskon tier lewat
`ResolveMemberPricing`), M6 kartu stempel (`MemberPricing::stampFree`), M7 voucher ulang tahun & kode promo
(`MemberPricing` voucher) + laporan member + ringkasan harian + import/export. Lalu self-order S1–S4 (ADR 0014).

Catatan untuk tim Flutter (repo aplikasi): **F3** cari/daftar member (`/v1/members`), pasang `member_id`; **F4** metode
bayar `category: points` hanya bila member terpasang, kirim `member_code` (6 digit terakhir hasil scan QR) atau
`member_pin`; tampilkan `member_discount`, `points_earned`, `member_points` di struk. Error baru: `MEMBER_NOT_FOUND`,
`MEMBER_PROGRAM_DISABLED`, `INSUFFICIENT_POINTS`, `INVALID_MEMBER_PROOF`, `MEMBER_PIN_LOCKED`.

Data lokal demo: program member **aktif** di "Kopi Senja (Demo)", member "Rina Kusuma" (0812-3456-7890) dan
"Member Demo" (0812-7777-1234, kata sandi `demomember1`, dibuat lewat halaman member saat uji).

## 2. Belum dikerjakan

| Prioritas | Pekerjaan | Catatan |
|---|---|---|
| 0 | Tindak lanjut modul Laporan | Lihat tabel "Perlu diubah / dilanjutkan" di bawah |
| ✅ | Kasir web (SPEC Q54, W1–W6) | Selesai 2026-10-11: akses per karyawan, login `/cashier`, shift X/Z, layar transaksi, open bill, approval PIN, void, menu Perangkat. Sisa: uji di browser tablet sungguhan & printer thermal; cetak tanpa dialog di luar cakupan |
| ✅ | Minggu 7 — panel admin | Selesai 2026-10-11 (lihat PROGRESS); sisa OWASP ZAP & uji manual di staging |
| 3 | Minggu 8 — deploy | cPanel, uji MySQL ulang (fitur 9–10 Okt & migrasi multi-outlet), uji dengan Flutter, pilot |
| ✅ | Import produk dari Excel | Selesai 2026-10-11 (`489e114`, Q59) |
| 2 | Integrasi Flutter | L1 + F1–F2 (repo aplikasi) |
| Ditunda | Aplikasi tablet | Fase tablet/aplikasi kasir (menu Perangkat sudah ada, Q54) |

### Perlu diubah / dilanjutkan (modul Laporan & UI)
| # | Pekerjaan | Kenapa | Catatan |
|---|---|---|---|
| L1 | Integrasi Flutter | Endpoint laporan baru & izin supervisor/kasir belum dipakai aplikasi | `docs/api/report.md`: supervisor ≤ 31 hari (422 di luar itu), kasir `shifts/{id}` + `/pdf` (X/Z-report) |
| L2 | Uji migrasi `category_name` & `unit_cost` di MySQL dengan data besar | Backfill `UPDATE` per kategori; perlu dicek waktu jalannya di hosting | Jalankan di salinan DB produksi sebelum deploy |
| L3 | Font PDF Plus Jakarta Sans | Kini DejaVu Sans | Tambahkan TTF (lisensi OFL) ke `resources/fonts/` — butuh persetujuan pemilik untuk mengunduh — ✅ Selesai 2026-10-11: TTF Regular/Bold + OFL.txt dari github.com/tokotype/PlusJakartaSans, subsetting aktif, cache `storage/fonts` |
| L4 | Uji batas export di hosting | dompdf & Excel sinkron memakai memori/waktu PHP shared hosting | Bila perlu turunkan `ReportExporter::MAX_PDF_ROWS` / `MAX_EXCEL_ROWS` |
| L5 | Total di footer tabel produk/kategori/opsi | Tabel array Filament tidak mendukung summarizer; total kini di deskripsi tabel | Opsional: `contentFooter()` khusus — ✅ Selesai (`2990a64`): baris total `<tfoot>` via `ReportTableWidget::totalRow()` |
| L6 | Seragamkan ukuran tersisa (opsional) | Paginasi 28px, ikon aksi baris 26px, tab garis bawah Penjualan/Shift 39px | Sengaja tidak diubah (standar tabel/navigasi) — tanyakan pemilik — ✅ Selesai: paginasi, aksi baris, tab kartu = 32px (`--control-h`); tinggi baris tabel tetap 37px |
| L7 | Perbarui dokumentasi OpenAPI (Scramble) & `docs/api/README.md` (jumlah endpoint) | 9 endpoint laporan baru | — — ✅ Selesai (`a2a33bd`): 58 endpoint, Scramble tanpa masalah (`scramble:analyze`) |

### Rekomendasi tambahan
| # | Rekomendasi | Manfaat |
|---|---|---|
| R1 | Snapshot `cost_price` ke `order_items` saat checkout → laporan HPP & margin kotor per produk/kategori | Finance: laba kotor akurat walau harga modal berubah (`products.cost_price` sudah ada) — ✅ Snapshot selesai (`2a6a926`, Q53: `order_items.unit_cost`); laporan Laba kotor ✅ (tab + API `/reports/profit`) |
| R2 | Cache agregat laporan (driver `database`, 60 detik, kunci tenant + filter; periode yang sudah lewat lebih lama) — sekaligus menutup T1 | Beranda/Laporan lebih ringan di shared hosting; `daily`/`hourly`/`heatmap` kini mengelompokkan di PHP — ✅ Selesai: `CachedReportService` (60 detik, periode lewat 10×, "Muat ulang" = flush per tenant) |
| R3 | Throttle aksi & endpoint export (mis. 10/menit per user) | Export sinkron cukup berat; cegah penyalahgunaan (DoS ringan) — ✅ Selesai (`bd473df`): `ExportThrottle`, `pos.rate_limits.export` |
| R4 | Wajib email terverifikasi untuk login dashboard supervisor | Supervisor kini bisa login web (Q50); verifikasi (Q46) belum memblokir akses — ✅ Selesai (`ae629f7`) |
| R5 | Ringkasan harian otomatis (email/WhatsApp) lewat cron cPanel `schedule:run` | Owner tahu omzet & anomali (void, selisih kas) tanpa membuka dashboard — ✅ Email selesai (`27040e1`, Q57); WhatsApp belum |
| R6 | Target omzet harian/bulanan per outlet + progres di Beranda | Manager punya patokan, bukan hanya pembanding periode lalu — ✅ Selesai (`0b9e64a`, Q56) |
| R7 | Audit log (`spatie/laravel-activitylog`, Fase 2) terhubung ke panel Audit | Jejak perubahan harga, diskon, void lebih lengkap — ✅ Selesai (`9fedd51`, Q58); void & diskon tetap di panel Audit |
| R8 | Simpan preset filter per user ("Laporan mingguan outlet A") | Mempercepat rekap rutin — ✅ Selesai: grup aksi Preset di Laporan (tabel `report_presets`, maks. 10 per user) |
| R9 | Tabel data pendamping grafik (aksesibilitas `chart.md`) & uji E2E browser untuk alur laporan | Mutu & aksesibilitas — ✅ Tabel pendamping grafik (tren & jam ramai; kategori & metode bayar sudah ada). Uji E2E browser ✅ Dusk (`000d2f0`, `docs/standards/testing.md` §8) |

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
