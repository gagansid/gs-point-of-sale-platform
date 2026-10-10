# Form & Input

**Tujuan:** mengumpulkan data dengan sesedikit mungkin kesalahan.

## Kapan dipakai

- Halaman Create/Edit Resource, halaman pengaturan, form di dalam modal (≤ 5 field).
- Form > 5 field → halaman penuh, bukan modal.

## Anatomi

```
OVERLINE GRUP (opsional)
Label *
[ input 32px ...................... ]
Teks bantuan / pesan error (12px)
```

## Varian field

| Kebutuhan | Komponen Filament | Catatan |
|---|---|---|
| Teks pendek | `TextInput` | `->maxLength()` sesuai kolom DB |
| Teks panjang | `Textarea` | `->rows(3)`, `->autosize()` |
| Uang | `MoneyInput` (Shared) | Lihat [money-input.md](money-input.md) |
| Persen | `TextInput::numeric()` | `->suffix('%')->minValue(0)->maxValue(100)->step(0.01)` |
| Angka bulat | `TextInput::integer()` | Qty, stok, urutan |
| Pilihan ≤ 5 | `Radio` / `ToggleButtons` | Selalu terlihat, tanpa klik tambahan |
| Pilihan > 5 / relasi | `Select` | `->searchable()->preload()` bila ≤ 100 data |
| Pilihan dari enum | `Select::options(OrderType::class)` | Label dari `HasLabel` |
| Ya/tidak dengan efek langsung | `Toggle` | `is_active`, `track_stock`, `tax_inclusive` |
| Ya/tidak dalam daftar | `Checkbox` / `CheckboxList` | |
| Tanggal | `DatePicker` | `->native(false)->displayFormat('j M Y')` |
| PIN | `PinInput` (Shared) | 6 digit, `->password()->revealable(false)`, tanpa autocomplete |
| Gambar produk | `FileUpload::image()` | `->imageEditor()->imageEditorAspectRatios(['1:1'])->panelAspectRatio('1:1')->maxSize(1024)`; placeholder ikon + "Pilih gambar atau seret ke sini"; helper format & ukuran. Panel bergaris putus-putus (hover primary), pratinjau tanpa nama file/gradasi, tombol ✕/✎ bulat putih 28px dengan kursor pointer |
| Daftar berulang | `Repeater` tabel ringkas | Opsi dalam grup opsi: `->table([...TableColumn])->compact()->reorderableWithButtons()->reorderableWithDragAndDrop(false)->addable(false)`; satu baris per item (↑↓ · isian · hapus), input tanpa garis sampai hover/fokus; tombol "Tambah opsi" di `headerActions` card (kanan atas). **Tetap tabel di semua lebar** (bawaan Filament jadi tumpukan kartu di bawah ±576px); di HP kolom harga 112px, lebar min. 300px lalu geser |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Tinggi input | 32px (termasuk select bersearch) |
| Radius | 6px (`--control-radius`, sama dengan tombol) |
| Label | 12.5px / 500 `--text`; tanda wajib `*` merah |
| Font input | 13px |
| Teks bantuan & error | **12px** (lebih kecil dari label; bawaan Filament 14px). Bantuan `text-muted`, error danger |
| Jarak | Label → field 6px · field → bantuan/error 4px |
| Border | Garis 1px `--field-border` **tanpa bayangan** (bawaan Filament ring + `shadow-sm` dihapus); hover `--field-border-hover`; fokus: garis primary 1px + halo 3px `--field-focus-ring`; error: garis danger (+ halo saat fokus); nonaktif: latar `surface-secondary` |
| Jarak antar field | 16px |
| Card section | Judul 14px/600 + deskripsi 12.5px muted di atas isi, **tanpa garis pemisah**; padding 16px 20px 20px. Setiap section diberi `->description()` singkat |
| Kode produk | SKU: kode internal opsional (`KOP-001`). Barcode: angka di bawah garis barcode kemasan (EAN-13/UPC) untuk dipindai kasir; kosong untuk menu racikan |
| Kolom | `columns(2)` di ≥ md; field panjang (`Textarea`, `Repeater`) `columnSpanFull()` |
| Overline grup | 12px, 600, uppercase, `text-muted` |

## Perilaku & state

| State | Tampilan |
|---|---|
| Fokus | Garis primary 1px + halo lembut (bukan ring 2px) |
| Error | Border danger + pesan di bawah field (dari `lang/id/validation.php`) |
| Disabled | Latar `surface-secondary`, teks muted |
| Read-only hasil hitung server | `->disabled()->dehydrated(false)` + helper "Dihitung otomatis" |
| Simpan | Bar tombol **menempel di bawah layar** (`position: sticky`, latar blur, garis atas), rata kanan: `Batal` · `Simpan` (bawaan Filament "Buat" diganti lewat `lang/vendor/filament-panels/id/resources/pages/create-record.php`; "Buat & buat lainnya" → "Simpan & tambah lagi"). Tombol loading; setelah sukses → [notification](notification.md) "… berhasil disimpan" |
| Perubahan belum disimpan | Filament `unsavedChangesAlerts()` aktif di panel |

## Aksesibilitas

- Setiap field punya label (tidak hanya placeholder).
- Urutan tab mengikuti urutan visual; field pertama `->autofocus()` di halaman Create.
- Pesan error menyebut nama field.

## Implementasi

```php
public static function form(Form $form): Form
{
    return $form->schema([
        Section::make('Informasi produk')
            ->columns(2)
            ->schema([
                TextInput::make('name')->label('Nama')->required()->maxLength(120)->autofocus(),
                Select::make('category_id')->label('Kategori')->relationship('category', 'name')
                    ->searchable()->preload()->required(),
                MoneyInput::make('price')->label('Harga jual')->required(),
                MoneyInput::make('cost_price')->label('Harga modal')
                    ->helperText('Tidak ditampilkan ke kasir'),
                TextInput::make('sku')->label('SKU')->maxLength(50),
                TextInput::make('barcode')->label('Barcode')->maxLength(50),
            ]),

        Section::make('Stok')
            ->columns(2)
            ->schema([
                Toggle::make('track_stock')->label('Lacak stok')->live(),
                TextInput::make('stock_qty')->label('Stok saat ini')->integer()
                    ->visible(fn (Get $get) => $get('track_stock'))
                    ->disabled()->helperText('Ubah lewat menu Stok'),
            ]),
    ]);
}
```

Simpan data tetap lewat Action (`->using()` di Create/Edit page), bukan `Model::create()` langsung.

## Do / Don't

| Do | Don't |
|---|---|
| Kelompokkan field dengan `Section` berjudul | Satu form panjang tanpa pengelompokan |
| Helper text untuk aturan yang tidak jelas | Menaruh aturan di placeholder |
| Field hasil hitung server read-only | Membiarkan user mengetik total/subtotal |
| Validasi FormRequest/Filament sama dengan API | Aturan validasi berbeda antara API dan panel |
