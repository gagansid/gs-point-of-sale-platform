# Tabs

**Tujuan:** berpindah antara tampilan setara dari data yang sama.

## Kapan dipakai

| Jenis | Filament | Contoh |
|---|---|---|
| Tab daftar (filter cepat) | `ListRecords::getTabs()` | Penjualan: Semua · Open bill · Selesai · Void |
| Tab form | `Tabs::make()` | Pengaturan Outlet: Umum · Pajak & service · Struk |
| Tab laporan | Halaman kustom + `Tabs` | Ringkasan · Per produk · Per metode bayar |

**Tidak** dipakai untuk langkah berurutan (pakai `Wizard`), atau jika hanya ada 1 tab.

## Anatomi

`Semua (135) · Open bill (3) · Selesai (128) · Void (4)` — label + badge jumlah opsional, garis
bawah 2px primary pada tab aktif.

## Spesifikasi

| Properti | Nilai |
|---|---|
| Label | 13px / 500; aktif 600 `primary` |
| Indikator aktif | Garis bawah 2px primary |
| Badge jumlah | Pill gray kecil; dihitung dengan query ringan (`count()`) |
| Jumlah tab | 2–5 |

## Perilaku & state

- Tab aktif tersimpan di URL (`?activeTab=`) agar bisa dibagikan/di-refresh.
- Tab daftar bekerja sebagai filter, sehingga filter lain tetap berlaku.
- Form bertab: tab yang berisi field error diberi penanda merah (bawaan Filament).

## Aksesibilitas

Navigasi panah kiri/kanan antar tab (bawaan Filament); label tab unik.

## Implementasi

```php
public function getTabs(): array
{
    return [
        'all' => Tab::make('Semua'),
        'open' => Tab::make('Open bill')
            ->modifyQueryUsing(fn (Builder $q) => $q->where('status', OrderStatus::Open))
            ->badge(fn () => Order::query()->where('status', OrderStatus::Open)->count()),
        'completed' => Tab::make('Selesai')
            ->modifyQueryUsing(fn (Builder $q) => $q->where('status', OrderStatus::Completed)),
        'voided' => Tab::make('Void')
            ->modifyQueryUsing(fn (Builder $q) => $q->where('status', OrderStatus::Voided)),
    ];
}
```

## Do / Don't

| Do | Don't |
|---|---|
| Label singkat 1–2 kata | Tab dengan kalimat |
| Badge jumlah hanya untuk yang butuh perhatian (Open bill) | Badge di setiap tab dengan query berat |
