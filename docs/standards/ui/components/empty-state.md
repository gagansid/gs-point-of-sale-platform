# Empty State

**Tujuan:** menjelaskan mengapa tidak ada data dan apa langkah berikutnya.

## Kapan dipakai

| Situasi | Judul | Deskripsi | Aksi |
|---|---|---|---|
| Belum pernah ada data | "Belum ada {objek}" | Manfaat/langkah pertama | Tombol Tambah (jika punya permission) |
| Filter/pencarian tanpa hasil | "Tidak ada data yang cocok" | "Ubah kata kunci atau filter" | "Reset filter" |
| Data dibuat dari aplikasi lain | "Belum ada transaksi" | "Transaksi dari aplikasi kasir akan muncul di sini" | — |
| Widget/chart tanpa data | — | "Belum ada data pada periode ini" | — |

## Anatomi

```
          [ikon 40px dalam lingkaran gray-lt]
              Belum ada produk                 16px / 600
   Tambahkan produk pertama untuk mulai        13px muted, maks. 2 baris
                 berjualan
              [+ Tambah produk]
```

## Spesifikasi

| Properti | Nilai |
|---|---|
| Padding | 32px × 16px |
| Perataan | Tengah |
| Ikon | Heroicon outline yang sama dengan ikon menu, 40px, `text-muted` |
| Tombol | Primary `sm`/`md`, hanya satu |

## Perilaku & state

- Tombol aksi disembunyikan jika user tidak punya permission membuat data.
- Empty state hasil filter selalu menyediakan jalan keluar (reset).

## Aksesibilitas

Judul empty state berupa teks (bukan hanya ilustrasi).

## Implementasi

```php
return $table
    ->emptyStateIcon('heroicon-o-cube')
    ->emptyStateHeading('Belum ada produk')
    ->emptyStateDescription('Tambahkan produk pertama untuk mulai berjualan')
    ->emptyStateActions([
        CreateAction::make()->label('Tambah produk')->icon('heroicon-o-plus'),
    ]);
```

## Do / Don't

| Do | Don't |
|---|---|
| Kalimat yang menjelaskan langkah berikutnya | "No data" / "Data kosong" |
| Ikon sesuai menu | Ilustrasi besar yang memenuhi layar |
