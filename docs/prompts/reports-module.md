# Prompt — Modul Laporan Lengkap (gs.POS)

> Salin seluruh isi di bawah garis ke sesi Claude Code baru di repo `gs-point-of-sale-platform`.

---

Kamu bekerja di repo `gs-point-of-sale-platform` (branch `feature/pos`, PHP 8.2, Laravel 12,
Filament 5, Pest 3). Tugasmu: **mengembangkan modul Laporan** agar owner, manager, supervisor,
dan kasir bisa merekap hasil transaksi toko/kafe — harian, mingguan, bulanan, tahunan, atau rentang
kustom — dengan banyak filter, di dashboard web (`/dashboard`) dan API Flutter (`/api/v1`).

## 0. Wajib dibaca dulu (jangan menulis kode sebelum ini)

1. `CLAUDE.md`, `AGENTS.md` (alur kerja + Definition of Done), `docs/HANDOFF.md`, `docs/PROGRESS.md`.
2. `docs/SPEC.md`: bagian Role & permission, Laporan & setelan, keputusan Q21, Q23, Q38, Q41.
3. `docs/api/report.md`, `docs/standards/api/`, `docs/standards/testing.md`.
4. Standar UI: `docs/standards/ui/README.md`, `design-tokens.md`, `layout.md` (§4 anatomi halaman
   **Laporan**), `content.md`, dan `components/` → `stat-widget.md`, `chart.md`, `table.md`,
   `tabs.md`, `empty-state.md`, `section.md`, `badge.md`, `button.md`.
5. Kode yang **sudah ada** dan wajib dipakai ulang (jangan membuat duplikat):
   - `app/Services/Report/ReportService.php` — satu-satunya sumber angka laporan
     (`summary`, `products`, `slowProducts`, `paymentMethods`, `daily`, `dailyBreakdown`, `hourly`,
     `orderTypes`, `outlets`, `audit`).
   - `app/Services/Report/ReportPeriod.php` — preset, `normalize()`, pembanding, `utcRange()`, `label()`.
   - `app/Services/Report/ReportFilters.php` — filter tipe order, metode bayar, kasir.
   - `app/Filament/Dashboard/Pages/Home.php` + `Widgets/*` + `Widgets/Concerns/ReportWidget.php` —
     contoh pola filter live (`HasFiltersForm`, `$pageFilters`, session + query string).
   - `app/Filament/Dashboard/Pages/Reports.php` + `resources/views/filament/dashboard/pages/reports.blade.php`.
   - `app/Exports/SalesReportExport.php`, `ReportSheet.php`, `SafeCell.php` (anti formula injection).
   - `app/Http/Controllers/Api/V1/ReportController.php`, `app/Http/Requests/Api/V1/Report/ReportRequest.php`.
   - `app/Support/CurrentOutlet.php`, `app/Support/Money.php`, `app/Enums/UserRole.php`.

## 1. Kondisi sekarang (hasil pengecekan, verifikasi ulang)

- Halaman `Reports` baru berisi: filter Dari/Sampai, 4 stat, rincian, per produk, per metode bayar,
  export Excel. Filternya **tidak** memakai `ReportPeriod`/`ReportFilters` (logika periode diduplikasi
  di `Reports::period()`), tidak ada preset, pembanding, maupun filter lanjutan seperti di Beranda.
- Preset `ReportPeriod::PRESETS` belum punya minggu ini / minggu lalu / tahun ini / tahun lalu.
- Grafik tren hanya per jam (1 hari) atau per hari; belum ada pengelompokan per minggu / per bulan.
- **Permission:** `report.view` & `report.export` hanya owner + manager. Supervisor dan kasir
  belum punya akses laporan sama sekali → ini butuh **keputusan SPEC baru** (lihat §3).

## 2. Kebutuhan laporan

### 2.1 Periode

| Preset | Rentang (tanggal lokal outlet, inklusif) | Granularitas default tren |
|---|---|---|
| Hari ini / Kemarin | 1 hari | per jam |
| Minggu ini / Minggu lalu | Senin–Minggu (ISO) | per hari |
| 7 / 30 hari terakhir | relatif hari ini | per hari |
| Bulan ini / Bulan lalu | kalender | per hari |
| Tahun ini / Tahun lalu | 1 Jan–31 Des (tahun ini sampai hari ini) | per bulan |
| Kustom | DatePicker Dari–Sampai, maks. 366 hari | otomatis: ≤ 1 hari jam, ≤ 62 hari hari, ≤ 180 hari minggu, sisanya bulan |

- Tambahkan preset baru ke `ReportPeriod` (bukan di halaman). Tetap pakai `normalize()` dan `MAX_DAYS`.
- User boleh mengganti granularitas manual (Per jam / hari / minggu / bulan) bila masuk akal untuk rentangnya.
- Pembanding: pakai `ReportPeriod::COMPARES` yang sudah ada (periode sebelumnya / tahun lalu / tanpa).
- Aturan pengakuan omzet tetap (SPEC Q23): `completed_at`, void terpisah via `voided_at`.

### 2.2 Filter

Outlet (ikut pemilih sidebar, `CurrentOutlet`, ADR 0010) · Periode · Pembanding · Granularitas ·
Tipe order · Metode bayar (multi) · Kasir/karyawan (multi) · Kategori (multi) · Produk (multi, searchable) ·
Shift (khusus laporan shift).

- Perluas `ReportFilters` (kategori, produk, shift) — jangan buat kelas filter kedua.
- ID asing (tenant/outlet lain) harus menghasilkan data kosong / `404 NOT_FOUND` (fail-closed, Q38).
- Filter disimpan di session + query string seperti `Home`; tombol **Reset filter**.
- Tampilkan ringkasan filter aktif sebagai badge/pill kecil di bawah form filter.

### 2.3 Jenis laporan (satu Tab per jenis, `components/tabs.md`)

| # | Tab | Isi | Sumber |
|---|---|---|---|
| 1 | **Ringkasan** | Omzet, transaksi, rata-rata, item terjual (+ % perubahan vs pembanding), tren omzet sesuai granularitas, rincian finansial (bruto, diskon, service, pajak, pembulatan, neto, void) | `summary`, `daily` + agregasi baru minggu/bulan |
| 2 | **Produk** | Qty, omzet, % kontribusi, HPP/margin **hanya jika** datanya ada; produk tidak laku | `products`, `slowProducts` |
| 3 | **Kategori** | Qty & omzet per kategori (snapshot) | baru |
| 4 | **Opsi / add-on** | Opsi terlaris & tambahan omzet dari `order_item_options` | baru |
| 5 | **Metode bayar** | Jumlah & nominal per metode/kategori bayar | `paymentMethods` |
| 6 | **Karyawan** | Per kasir: transaksi, omzet, rata-rata, diskon diberikan, void | baru |
| 7 | **Shift** | Per shift: kasir, buka/tutup, kas awal, penjualan tunai, kas seharusnya vs aktual, selisih (badge success/danger), ditutup paksa | baru (pakai model `Shift`) |
| 8 | **Waktu** | Jam ramai (0–23) & heatmap hari-dalam-minggu × jam | `hourly` + baru |
| 9 | **Tipe order** | Dine-in / takeaway / dll | `orderTypes` |
| 10 | **Outlet** | Perbandingan antar-outlet (hanya saat "Semua outlet") | `outlets` |
| 11 | **Diskon & void** | Daftar diskon (pelaku, approver PIN, nominal), void (pelaku, approver, alasan), shift selisih kas | `audit` (tanpa limit, paginasi) |
| 12 | **Stok** | Pergerakan stok per produk & tipe (`StockMovementType`), stok menipis | baru, hanya bila `stock.adjust`/`report.view` |

Bila sebuah tab tidak relevan untuk role/outlet, sembunyikan (jangan tampil lalu ditolak).

## 3. Akses per role (sudah disetujui user)

Catat di `docs/SPEC.md` sebagai keputusan Q baru + tabel permission + `UserRole::permissions()`
(jangan pernah cek role langsung):

| Permission | Owner | Manager | Supervisor | Kasir | Cakupan |
|---|:-:|:-:|:-:|:-:|---|
| `report.view` (ada) | ✅ | ✅ | ❌ | ❌ | Semua tab, semua periode (maks. 366 hari per query), outlet yang dipegang |
| `report.export` (ada) | ✅ | ✅ | ✅ | ❌ | Excel & PDF untuk tab yang boleh dilihat user, dengan batas periode yang sama dengan layarnya |
| `report.view_shift` (baru) | ✅ | ✅ | ✅ | ❌ | Ringkasan, Produk, Metode bayar, Karyawan, Shift, Diskon & void — outlet yang dipegang, **maks. 31 hari ke belakang** dari hari ini (konstanta, mis. `ReportPeriod::SUPERVISOR_LOOKBACK_DAYS = 31`) |
| `report.view_own` (baru) | ✅ | ✅ | ✅ | ✅ | Rekap **shift sendiri**: penjualan, metode bayar, kas, produk terjual (X-report shift berjalan & Z-report saat tutup shift) |

- Supervisor tidak punya `report.view`, jadi preset/tanggal di luar 31 hari disembunyikan di UI
  **dan** dipotong di server (`normalize()` menerima batas bawah); API → `422 VALIDATION_ERROR`.
- Supervisor yang export hanya mendapat tab `report.view_shift`, tidak pernah tab Stok/Outlet/Kategori dsb.
- Pembatasan cakupan diterapkan di **server** (query/Action), bukan hanya disembunyikan di UI.
- Angka 31 hari adalah default yang diusulkan; konfirmasi ke user di tahap 1 sebelum dikunci di SPEC.

## 4. Desain & UI (wajib ikut standar yang ada)

- Anatomi halaman Laporan (`layout.md` §4): **filter di atas → stat widget → chart → tabel**,
  aksi kanan atas `Export Excel` (gray, satu-satunya; tidak ada tombol primary lain).
- Judul: ikon `chart-bar` + "Laporan" (trait `HasIconBreadcrumbs`), subjudul outlet seperti sekarang.
- Komponen Filament dulu; Blade kustom hanya bila perlu dan **hanya memakai token** dari
  `design-tokens.md` / `theme.css`. Dilarang hex atau `style=""` di Resource/Page/Blade.
- Warna: primary `#2D4282` untuk seri tunggal; banyak kategori pakai urutan `chart.md`
  (`#2D4282`, `#066FD1`, `#2FB344`, `#F59F00`, `#8A919E`, sisanya "Lainnya"). Warna status
  (success/warning/danger) hanya untuk makna (selisih kas, void), bukan kategori chart.
- Chart: tinggi 360px di Laporan, grid horizontal saja, sumbu `Rp1,2 jt`, tooltip `Rp1.250.500`,
  seri pembanding abu-abu putus-putus, kosong = "Belum ada data pada periode ini".
- Stat: 4 per baris di ≥ lg, 2 di md, 1 di mobile (`stat-widget.md`), nilai `tabular-nums`.
- Tabel: Filament `Table` (sort, search, paginasi), nominal rata kanan, `Money::format`,
  kolom prioritas rendah `toggleable`/`visibleFrom('md')`, baris total di footer (summarizer).
- Teks Indonesia singkat (`content.md`), tanggal `j M Y`, zona waktu outlet.
- Cek **light & dark mode** dan lebar **375px**. Tanpa polling (shared hosting).
- URL/slug & endpoint dalam **bahasa Inggris**: `/dashboard/reports?tab=products`,
  `/dashboard/reports/shift`, `/api/v1/reports/categories`, dst. Label UI Bahasa Indonesia.

## 5. API (Flutter)

- Endpoint baru di bawah `/api/v1/reports/*` (mis. `categories`, `options`, `employees`, `shifts`,
  `shifts/{id}` untuk rekap shift sendiri, `trend?granularity=day|week|month|hour`, `stock`).
- Parameter seragam: `from`, `to`, `outlet_id`, `compare`, `granularity`, dan filter §2.2.
  Validasi di `ReportRequest`; 422 `VALIDATION_ERROR` untuk format/rentang salah.
- Respons via `ApiResponse`, nominal string desimal (`"62000.00"`), `period` di setiap respons.
- Pakai kode error yang ada (`FORBIDDEN`, `NOT_FOUND`, `VALIDATION_ERROR`); kode baru wajib ditambah ke SPEC dulu.
- Dokumentasikan semua endpoint di `docs/api/report.md`.

## 6. Export

- Excel (`maatwebsite/excel`): satu sheet per tab yang terlihat untuk user, dengan filter yang sama
  dengan layar; header berisi outlet, periode, filter aktif, waktu cetak. Nominal sebagai angka.
  Semua teks user lewat `SafeCell`. Nama file: `laporan-{tab|lengkap}-{from}-{to}.xlsx`.
- **Tanpa queue** (keputusan user): semua export sinkron, langsung diunduh. Jaga agar ringan:
  data sudah teragregasi di SQL, rentang maks. 366 hari, tabel detail (diskon/void/shift) memakai
  `cursor()`/chunk. Bila tetap terlalu berat, tolak dengan pesan jelas ("Persempit rentang tanggal"),
  jangan diam-diam memotong data.
- **PDF wajib** (keputusan user):
  - Paket: `barryvdh/laravel-dompdf` (PHP murni, jalan di shared hosting; **jangan** Browsershot/
    Puppeteer/wkhtmltopdf karena butuh Chrome/biner). Pastikan versinya kompatibel PHP 8.2 & Laravel 12,
    catat di tabel paket `docs/SPEC.md` dan buat ADR singkat.
  - Aksi header `Export` menjadi grup (gray): **Excel** dan **PDF**; tetap tanpa tombol primary.
  - Template Blade khusus PDF (`resources/views/reports/pdf/*.blade.php`), A4 portrait (landscape untuk
    tabel lebar), font Plus Jakarta Sans di-embed, warna dari token (`--brand-navy` untuk header,
    primary untuk aksen, Slate untuk teks/border). CSS khusus PDF dibolehkan di template ini karena
    dompdf tidak membaca `theme.css`; nilai hex wajib sama dengan `design-tokens.md` (definisikan sekali
    di satu partial).
  - Isi: kop (logo gs.POS / nama tenant, outlet, periode, filter aktif, dicetak oleh & waktu cetak
    zona outlet), stat ringkas, tabel per tab, nomor halaman di footer. Grafik: tabel ringkas saja
    (dompdf tidak menjalankan JS); grafik batang sederhana boleh dengan elemen HTML/CSS.
  - Rekap shift (Z-report) kasir juga bisa diunduh PDF oleh pemilik shift (`report.view_own`).
  - Escape semua teks user (`{{ }}`), larang remote resource (`isRemoteEnabled=false`), dan matikan PHP di dompdf.
  - Nama file: `laporan-{tab|lengkap}-{from}-{to}.pdf`. Test: response `application/pdf`, permission, isolasi tenant.

## 7. Performa & keamanan

- Semua agregasi di SQL (`SUM`/`COUNT`/`GROUP BY`), bukan di PHP; kompatibel MySQL 8 **dan**
  MariaDB 10.6 **dan** SQLite (test). Pengelompokan minggu/bulan memakai tanggal lokal outlet.
- Tambah index bila perlu lewat migration baru (cek `add_report_indexes_to_orders_and_shifts`).
- Isolasi tenant (`BelongsToTenant`) + outlet (`CurrentOutlet`, Q38) di setiap query.
- Jangan pernah `migrate:fresh` / `--env=testing` di DB lokal.

## 8. Testing (Pest)

Untuk setiap agregasi baru di `ReportService`, setiap endpoint, dan halaman Filament:
sukses · validasi gagal · permission ditolak (per role: owner, manager, supervisor, kasir) ·
isolasi tenant A vs B · outlet di luar penugasan → 404 · batas hari zona waktu (23.30 / 00.30 WIB) ·
batas minggu (Senin), bulan, tahun, tahun kabisat · void tidak masuk omzet · kasir hanya melihat shift sendiri ·
export berisi sheet sesuai permission + `SafeCell`. Jalankan `php artisan test`, `./vendor/bin/pint`,
`./vendor/bin/phpstan analyse` (level 6) sebelum commit.

## 9. Cara kerja

Kerjakan **bertahap**; di akhir setiap tahap tampilkan ringkasan perubahan + hasil test, lalu tanya **"Lanjut?"**.

1. **Rencana & keputusan** — rangkum temuan, konfirmasi batas 31 hari supervisor (§3) dan versi paket PDF (§6),
   lalu tulis Q baru di SPEC + ADR paket PDF.
2. **Fondasi** — preset & granularitas di `ReportPeriod`, perluasan `ReportFilters`, refactor `Reports`
   agar memakai keduanya (hapus duplikasi `period()`), struktur tab + filter live.
3. **Tab owner/manager** — Ringkasan → Produk → Kategori → Opsi → Metode bayar → Karyawan → Waktu → Tipe order → Outlet → Diskon & void → Stok.
4. **Laporan shift & akses supervisor/kasir** — permission baru, tab Shift, rekap shift sendiri (web + API).
5. **API** endpoint baru + `docs/api/report.md`.
6. **Export** Excel & PDF per tab / lengkap (sinkron, tanpa queue).
7. **Verifikasi UI** di browser (light/dark, 375px) + update `docs/PROGRESS.md`, `docs/HANDOFF.md`.

Commit per tahap, Conventional Commits scope `report` (mis. `feat(report): add weekly and yearly presets`),
di branch `feature/pos`. Jangan push tanpa diminta.
