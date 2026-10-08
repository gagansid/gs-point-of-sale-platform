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
│ # │ NOMOR ORDER      │ WAKTU     │ STATUS   │      TOTAL │            │
├───┼──────────────────┼───────────┼──────────┼────────────┼────────────┤
│ 1 │ JKT01-261008-042 │ 14.02     │ (Selesai)│   Rp62.000 │  👁  ✎  ⋯  │
├───┴──────────────────┴───────────┴──────────┴────────────┴────────────┤
│ Menampilkan 1–20 dari 135                      ‹ 1 2 3 … 7 ›  [20 ▾]  │
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
| Gambar | `ImageColumn` | 40px, `->square()`, radius 8px |
| Aksi | Lihat [row-actions.md](row-actions.md) | Selalu kolom terakhir |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Header kolom | 12px, 600, uppercase, `text-muted`, latar `surface-secondary` |
| Sel | 13px, padding 12×16px |
| Pemisah baris | 1px `--border-light` |
| Hover baris | Latar `surface-secondary`; seluruh baris bisa diklik ke detail (`->recordUrl()`) |
| Pencarian | Lebar 260px (penuh di mobile), placeholder spesifik |
| Pagination | `[10, 20, 50, 100]`, default 20 — sama dengan API |
| Urutan default | Terbaru dulu (`->defaultSort('created_at', 'desc')`) kecuali master (`sort_order`/nama) |

## Perilaku & state

| State | Perilaku |
|---|---|
| Memuat | Skeleton bawaan Filament |
| Kosong | [Empty state](empty-state.md) dengan aksi Tambah |
| Hasil filter kosong | "Tidak ada data yang cocok" + tombol "Reset filter" |
| Filter aktif | Indikator jumlah filter pada tombol Filter; filter disimpan di session (`->persistFiltersInSession()`) |
| Data ditandai | Stok ≤ 0: nilai stok `danger`; produk nonaktif: baris teks muted |

## Filter standar per halaman

| Halaman | Filter |
|---|---|
| Penjualan | Rentang tanggal (default hari ini), status, shift, metode bayar, kasir |
| Shift | Rentang tanggal, kasir, status, "ada selisih" |
| Produk | Kategori, aktif/nonaktif, lacak stok, stok ≤ 0 |
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
        ->paginated([10, 20, 50, 100])
        ->defaultPaginationPageOption(20)
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
