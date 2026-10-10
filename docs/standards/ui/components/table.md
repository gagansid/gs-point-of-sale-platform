# Table

**Tujuan:** menampilkan, mencari, dan memfilter daftar data.

## Kapan dipakai

- Semua halaman daftar (List) dan relation manager.
- **Bukan** untuk data detail satu objek (pakai [infolist.md](infolist.md)) atau angka ringkasan
  (pakai [stat-widget.md](stat-widget.md)).

## Anatomi

```
┌───────────────────────────────────────────────────────────────────────┐
│ [Semua] [Selesai] [Void]          (tabs, opsional)                     │
├───────────────────────────────────────────────────────────────────────┤
│ 🔍 Cari nomor order…        [Filter ▾] [Kolom ▾]                       │
├───┬──────────────────┬───────────┬──────────┬────────────┬────────────┤
│ ☐ │ Nomor order      │ Waktu     │ Status   │      Total │            │
├───┼──────────────────┼───────────┼──────────┼────────────┼────────────┤
│ 1 │ JKT01-261008-042 │ 14.02     │ (Selesai)│   Rp62.000 │  👁  ✎  ⋯  │
├───┴──────────────────┴───────────┴──────────┴────────────┴────────────┤
│ 1–20 dari 135  [20 / hal ▾]                         ‹ 1 2 3 … 7 ›    │
└───────────────────────────────────────────────────────────────────────┘
```

## Varian kolom

| Data | Kolom | Aturan |
|---|---|---|
| Nama utama | `TextColumn` | `->searchable()->sortable()->weight('medium')`; deskripsi kecil via `->description()` |
| Kode / nomor | `TextColumn` | `->fontFamily('mono')->copyable()` |
| Uang | `MoneyColumn` | Rata kanan, `->sortable()`, `->summarize()` bila relevan |
| Angka | `TextColumn::numeric(locale: 'id')` | Rata kanan |
| Status / enum | `TextColumn::badge()` | Lihat [badge.md](badge.md) |
| Boolean aktif | `IconColumn::boolean()` atau `ToggleColumn` | `ToggleColumn` hanya bila perubahan aman & langsung (availability) |
| Tanggal | `TextColumn::dateTime('j M Y, H.i')` | `->sortable()`, zona outlet |
| Relasi | `TextColumn('category.name')` | Eager load via `modifyQueryUsing` |
| Gambar | `ImageColumn` | 24px, `->square()`, radius 4px (baris tetap 37px); `->toggleable(isToggledHiddenByDefault: true)` bila banyak data tanpa gambar |
| Aksi | Lihat [row-actions.md](row-actions.md) | Selalu kolom terakhir |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Header card | Min. 52px, padding 10×16px: judul 15px/600 · pencarian 240px · tombol ikon 32px · tombol `+ Tambah` 32px. Badge jumlah filter di sudut tombol filter, disembunyikan saat 0 |
| Header kolom | Tinggi 40px, 13px/700 `--text`, tanpa uppercase, latar `surface`, garis bawah `--border-color` |
| Sel | 12.5px/20px `--text` (warna sama dengan header kolom), padding 8×12px → baris 37px; badge 20px; ikon status 16px; tombol aksi 26px, ikon aksi 14px |
| Pemisah baris | 1px `--border-light` |
| Hover baris | Latar `surface-secondary`; seluruh baris bisa diklik ke detail (`->recordUrl()`) |
| Pencarian | Lebar 240px, tinggi 32px (penuh di mobile), placeholder spesifik |
| Baris terpilih | Latar `--primary-lt` |
| Urutkan | Semua kolom data `->sortable()`; ikon di kanan judul: `↕` (chevron-up-down, `--text-disabled`) saat belum aktif, `↑`/`↓` `--primary` saat aktif (alias ikon di `Layout`) |
| Pilih baris | Checkbox di kiri muncul bila ada aksi massal (`->toolbarActions([BulkActionGroup])`). Aksi massal memanggil Action bisnis per rekaman, wajib konfirmasi, cek permission, `->deselectRecordsAfterCompletion()` |
| Panel filter | **Lipat** di antara header card dan isi tabel: `->filtersLayout(FiltersLayout::AboveContentCollapsible)->filtersFormColumns(['default' => 1, 'sm' => 2, 'lg' => 4])`. Dibuka/ditutup dengan slide 250ms (`x-collapse`, override `resources/views/vendor/filament-tables/components/filters.blade.php`), latar `surface-secondary`, tanpa judul. Footer: `Atur ulang` (abu) + `Terapkan filter` (primary) — global di `Layout`. Tombol filter tetap di header (urutan: cari · filter · kolom · tambah) |
| Tab cepat | Di **dalam header card** (baris kedua, garis bawah 2px primary, angka dalam pill; merah/kuning hanya bila > 0) — trait `HasCardTabs` + `resources/views/filament/shared/card-tabs.blade.php`, bukan pill di atas card. Tab aktif disimpan di session (tetap setelah refresh/pindah menu); halaman wajib mendeklarasikan ulang `public ?string $activeTab = null;` tanpa `#[Url]` |
| Checkbox | Kolom pilih 16px dari tepi card, 4px ke kolom berikut (sejajar judul card) |
| URL | Filter **tidak** ditulis ke URL: halaman List mendeklarasikan ulang `public ?array $tableFilters = null;` tanpa `#[Url]`; filter bertahan lewat `->persistFiltersInSession()`. Pencarian & urutan tetap di URL (`?search=`, `?sort=`) |
| Pagination | `[5, 10, 25, 50, 100]`, default 10 — global lewat `Table::configureUsing()` di `App\Filament\Shared\Layout`, jangan set per tabel (API tetap `[10, 20, 50, 100]`). Kiri: `1–10 dari 135` + pilihan `10 ▾` 28px (diingat per tabel lewat `->persistRecordsPerPageInSession()`); kanan: pager 28px `‹ 1 2 ›` selalu tampil (non-aktif bila 1 halaman). Data per halaman diambil dari server (Livewire `gotoPage`). Markup: `resources/views/vendor/filament/components/pagination/index.blade.php`, teks: `lang/vendor/filament/id/components/pagination.php` |
| Urutan default | Terbaru dulu (`->defaultSort('created_at', 'desc')`) kecuali master (`sort_order`/nama) |

## Perilaku & state

| State | Perilaku |
|---|---|
| Memuat | Skeleton bawaan Filament |
| Kosong | [Empty state](empty-state.md) tanpa tombol (tombol `+` sudah ada di header card): "Belum ada {objek}" |
| Hasil filter/pencarian kosong | "Tidak ada {objek} yang cocok" + "Ubah kata kunci atau atur ulang filter" (cek `$table->isFiltered()` / `getTableSearch()`) |
| Filter aktif | Indikator jumlah filter pada tombol Filter; filter disimpan di session (`->persistFiltersInSession()`) |
| Data ditandai | Stok ≤ 0: nilai stok `danger`; produk nonaktif: baris teks muted |

## Konvensi semua halaman daftar

Semua tabel memakai pola yang sama dengan Produk (dijaga `tests/Feature/Filament/TableConventionsTest.php`):

| Aspek | Aturan |
|---|---|
| Global (`Layout::configureActions`) | Panel filter lipat 4 kolom, filter/pencarian/urutan/baris per halaman di session, `[5,10,25,50,100]`, ikon urut, reset filter di footer |
| Halaman | Deklarasikan ulang `public ?array $tableFilters = null;` (dan `public ?string $activeTab = null;` bila ada tab) tanpa `#[Url]` |
| Tombol tambah | `CreateAction::make()->label('Tambah')->icon(Heroicon::OutlinedPlus)` di `getTableCardActions()`; judul modal tetap spesifik ("Tambah kategori") |
| Aksi baris | Satu ikon utama (Ubah untuk master data, Lihat untuk transaksi) + `⋯` berisi sisanya (Hapus, Tutup paksa, Void, Suspend) |
| Kolom | Semua kolom data `->sortable()`; maks. 7 terlihat — sisanya `->toggleable(isToggledHiddenByDefault: true)` |
| Empty state | `TableEmptyState::apply($table, $icon, 'objek', 'deskripsi')` — tanpa tombol, pesan beda saat dipersempit |
| Status aktif | Master data ber-`is_active`: kolom ikon "Aktif", tab `StatusTabs::make(Model::class)`, aksi `⋯` `ActiveStatusActions::make(...)` (Nonaktifkan berkonfirmasi, Aktifkan langsung), toggle di form |
| Hapus massal | `BulkDeleteAction::make(Model::class, fn ($r) => app(DeleteX::class)->handle($r), 'objek', 'catatan', 'permission')` — hanya master data. **Tidak** untuk transaksi/audit (penjualan, shift, tenant) |
| Tab cepat | `HasCardTabs` + `getTabs()`; status yang punya tab tidak diulang sebagai filter. Angka di tab hanya bila tidak dibatasi filter tanggal (Penjualan & Shift tanpa angka) |

| Halaman | Tab | Filter | Massal |
|---|---|---|---|
| Produk | Semua · Favorit · Aktif · Habis · Stok menipis | lihat di bawah | Tandai habis/tersedia, Hapus |
| Kategori | Semua · Aktif · Nonaktif | — (atur urutan kasir) | Hapus |
| Grup opsi | Semua · Aktif · Nonaktif | Aturan (wajib/opsional), Pemakaian | Hapus |
| Penjualan | Semua · Selesai · Open bill · Void | Tanggal, tipe, kasir | — |
| Shift | Semua · Buka · Ditutup · Ada selisih | Tanggal dibuka | — |
| Tenant (`/admin`) | Semua · Aktif · Trial · Suspended · Segera berakhir | Jenis usaha | — |

## Filter standar per halaman

| Halaman | Filter |
|---|---|
| Penjualan | Rentang tanggal (default hari ini), status, shift, metode bayar, kasir |
| Shift | Rentang tanggal, kasir, status, "ada selisih" |
| Produk | Tab: Semua · Favorit · Aktif · Habis · Stok menipis (badge jumlah) di header card. Filter: kategori (multi), grup opsi (multi), status aktif, ketersediaan, favorit, lacak stok, rentang harga, stok ≤ 0. Kolom bintang (klik = favorit), stok kuning ⚠ bila menipis. Urutan bawaan `sort_order` → nama (`->reorderable('sort_order')` = atur urutan kasir) |
| Karyawan | Role, aktif/nonaktif |

## Aksesibilitas

- Placeholder pencarian menyebut field yang dicari: "Cari nama, SKU, atau barcode…".
- Kolom aksi berisi tombol dengan tooltip/label.

## Implementasi

```php
public static function table(Table $table): Table
{
    return $table
        ->modifyQueryUsing(fn (Builder $query) => $query->with(['shift.openedBy']))
        ->columns([
            TextColumn::make('order_number')->label('Nomor order')
                ->fontFamily('mono')->searchable()->copyable(),
            TextColumn::make('created_at')->label('Waktu')->dateTime('j M Y, H.i')->sortable(),
            TextColumn::make('shift.openedBy.name')->label('Kasir')->toggleable(),
            TextColumn::make('status')->label('Status')->badge(),
            MoneyColumn::make('grand_total')->label('Total')->sortable()
                ->summarize(Sum::make()->money('IDR', locale: 'id')->label('Total')),
        ])
        ->filters([
            DateRangeFilter::make('created_at')->label('Tanggal')->default('today'),
            SelectFilter::make('status')->label('Status')->options(OrderStatus::class),
        ])
        ->actions([ViewAction::make()->iconButton()])
        ->defaultSort('created_at', 'desc')
        ->persistFiltersInSession()
        ->emptyStateHeading('Belum ada transaksi')
        ->emptyStateDescription('Transaksi dari aplikasi kasir akan muncul di sini');
}
```

`DateRangeFilter` adalah filter Shared (`app/Filament/Shared/Filters`) berbasis dua `DatePicker`.

## Do / Don't

| Do | Don't |
|---|---|
| Eager load relasi yang tampil | N+1 query dari kolom relasi |
| Maksimal 7 kolom terlihat di desktop | Menampilkan semua kolom DB |
| Kolom uang rata kanan + total di footer | Total dihitung manual di Blade |
| Default filter tanggal = hari ini untuk transaksi | Memuat seluruh riwayat transaksi tanpa filter |
