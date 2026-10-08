# Section / Card

**Tujuan:** mengelompokkan konten yang saling terkait dalam satu kartu.

## Kapan dipakai

- Kelompok field form, kelompok info di halaman detail, pembungkus widget kustom.
- **Bukan** untuk membungkus satu field saja, atau menumpuk kartu di dalam kartu (maks. 1 level).

## Anatomi

```
┌──────────────────────────────────────────────────┐
│ Judul section                     [aksi header]  │  16px / 600
│ Deskripsi singkat (opsional)                     │  13px muted
├──────────────────────────────────────────────────┤
│ Konten (padding 24px)                            │
└──────────────────────────────────────────────────┘
```

## Varian

| Varian | Filament | Kapan |
|---|---|---|
| Section standar | `Section::make('Judul')` | Default |
| Dengan deskripsi | `->description('...')` | Aturan bisnis perlu dijelaskan (pajak, pembulatan) |
| Aside | `->aside()` | Halaman pengaturan: judul+deskripsi kiri, field kanan |
| Collapsible | `->collapsible()->collapsed()` | Info tambahan yang jarang dibuka |
| Tanpa header | `Section::make()->schema(...)` | Panel ringkasan di kolom kanan |
| Fieldset | `Fieldset::make('Judul')` | Sub-kelompok di dalam section (jarang) |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Latar | `--surface` |
| Border / bayangan | `--shadow-card` |
| Radius | 12px |
| Padding | 24px (16px di mobile) |
| Jarak antar section | 24px |
| Judul | 16px / 600, `--text` |
| Deskripsi | 13px, `--text-muted` |

## Perilaku & state

- Section berisi field wajib tidak boleh `collapsed()` secara default.
- Aksi header section (mis. "Ubah") memakai tombol `sm` gray atau link.

## Aksesibilitas

Judul section menjadi heading; jangan menulis judul kosong pada section yang punya banyak field.

## Implementasi

```php
Section::make('Pajak & service charge')
    ->description('Diterapkan ke semua transaksi baru. Transaksi lama tidak berubah')
    ->aside()
    ->schema([
        TextInput::make('tax_rate')->label('Pajak')->numeric()->suffix('%'),
        Toggle::make('tax_inclusive')->label('Harga sudah termasuk pajak'),
        TextInput::make('service_charge_rate')->label('Service charge')->numeric()->suffix('%'),
    ]);
```

## Do / Don't

| Do | Don't |
|---|---|
| Judul berupa kata benda ("Informasi produk") | Judul generik ("Detail", "Form") |
| `->aside()` untuk pengaturan | Section bertingkat 3 level |
