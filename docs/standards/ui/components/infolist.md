# Infolist (halaman detail)

**Tujuan:** menampilkan detail satu objek secara rapi dan mudah dipindai.

## Kapan dipakai

- Halaman View: detail order, shift, tenant, karyawan.
- **Bukan** untuk daftar banyak objek (pakai [table.md](table.md)).

## Anatomi

Pola "label kiri, nilai tebal kanan" dari `gs-task-tracker` (`.info-table`) untuk panel ringkas,
dan grid 2–4 kolom untuk detail lengkap:

```
┌ Ringkasan ──────────────────────┐   ┌ Detail order ─────────────────────────────┐
│ Subtotal            Rp70.000    │   │ NOMOR ORDER        WAKTU          KASIR    │
│ Diskon              -Rp5.000    │   │ JKT01-261008-0042  8 Okt, 14.02   Rina     │
│ Service (5%)         Rp3.250    │   │ TIPE               MEJA           STATUS   │
│ Pajak (11%)          Rp7.508    │   │ Makan di tempat    Meja 5         (Selesai)│
│ Pembulatan             -Rp58    │   └───────────────────────────────────────────┘
│ ─────────────────────────────── │
│ Total               Rp75.700    │   ┌ Item ─────────────── (tabel relasi) ──────┐
└─────────────────────────────────┘
```

## Varian

| Varian | Filament | Kapan |
|---|---|---|
| Grid | `Section::make()->columns(3)` + `TextEntry` | Atribut objek |
| Ringkasan nominal | `TextEntry::inlineLabel()` + `MoneyEntry` | Rincian total, ringkasan shift |
| Tabel detail ringkas | `View` + `.gs-detail-table` (`filament/dashboard/orders/items`) | Item order + opsi, pembayaran, rekap per metode bayar — satu baris per data, bukan kartu per item |
| Ringkasan dengan total | `Section::extraAttributes(['class' => 'gs-summary'])` + `extraEntryWrapperAttributes(['class' => 'gs-summary-total'])` | Ringkasan order, kas shift |
| Relasi besar | Relation manager (tabel) | Order dalam shift |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Label | 12px / 500, `text-muted` (uppercase hanya di grid) |
| Nilai | 13.5px, `--text` |
| Nilai kosong | `—` warna `text-disabled` |
| Baris total | 16px / 600, garis pemisah di atasnya |
| Nominal | Rata kanan, tabular |

## Perilaku & state

- Nilai yang bisa disalin (nomor order, device UID) memakai `->copyable()`.
- Nilai merujuk ke objek lain menjadi link (`->url()`), mis. shift → halaman shift.

## Aksesibilitas

Label selalu tampil (bukan hanya tooltip), urutan baca kiri→kanan, atas→bawah.

## Implementasi

```php
public static function infolist(Infolist $infolist): Infolist
{
    return $infolist->schema([
        Section::make('Detail order')->columns(3)->schema([
            TextEntry::make('order_number')->label('Nomor order')->fontFamily('mono')->copyable(),
            TextEntry::make('created_at')->label('Waktu')->dateTime('j M Y, H.i'),
            TextEntry::make('shift.openedBy.name')->label('Kasir'),
            TextEntry::make('order_type')->label('Tipe')->badge(),
            TextEntry::make('table_label')->label('Meja')->placeholder('—'),
            TextEntry::make('status')->label('Status')->badge(),
        ]),
        Section::make('Ringkasan')->schema([
            MoneyEntry::make('subtotal')->label('Subtotal')->inlineLabel(),
            MoneyEntry::make('discount_total')->label('Diskon')->inlineLabel()->negative(),
            MoneyEntry::make('grand_total')->label('Total')->inlineLabel()->size('lg')->weight('semibold'),
        ]),
    ]);
}
```

## Do / Don't

| Do | Don't |
|---|---|
| Tampilkan snapshot nama & harga dari `order_items` | Mengambil harga produk saat ini untuk order lama |
| `—` untuk nilai kosong | Sel kosong tanpa penanda |

## Breadcrumb halaman satu data

`Menu › Detail › Nama data` (halaman lihat) dan `Menu › Ubah › Nama data` (halaman ubah), diatur
`HasIconBreadcrumbs`. Resource tanpa kolom judul memakai `getRecordTitle()` + `hasRecordTitle()`
(mis. shift: "Budi · 10 Okt 2026, 08.00").
