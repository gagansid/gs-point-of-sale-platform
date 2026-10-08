# Stat Widget

**Tujuan:** menampilkan satu angka penting beserta konteksnya.

## Kapan dipakai

- Beranda dashboard (omzet hari ini, jumlah transaksi, rata-rata transaksi, produk stok ≤ 0),
  bagian atas halaman laporan, ringkasan shift.
- **Bukan** untuk perbandingan banyak kategori (pakai [chart.md](chart.md)).

## Anatomi

```
┌─────────────────────────────┐
│ Omzet hari ini         [💰] │  label 13px muted + ikon 20px
│ Rp4.250.000                 │  nilai 28px / 600, tabular
│ ↑ 12% dari kemarin          │  deskripsi 12px, success/danger
│ ▁▂▃▅▆▇ (sparkline opsional) │
└─────────────────────────────┘
```

## Varian

| Varian | Kapan |
|---|---|
| Nilai saja | Angka tanpa pembanding yang berarti |
| Dengan tren | Ada pembanding periode sebelumnya |
| Dengan sparkline | Tren 7 hari |
| Peringatan | Nilai butuh tindakan (stok ≤ 0 > 0 produk) → deskripsi `warning`/`danger` + link ke daftar |

## Standar widget beranda `/dashboard`

| Urutan | Label | Nilai | Deskripsi |
|---|---|---|---|
| 1 | Omzet hari ini | Σ `grand_total` order selesai | Tren vs kemarin |
| 2 | Transaksi | Jumlah order selesai | Tren vs kemarin |
| 3 | Rata-rata transaksi | Omzet ÷ transaksi | — |
| 4 | Stok habis | Jumlah produk `track_stock` dengan stok ≤ 0 | Link "Lihat produk" |

## Spesifikasi

| Properti | Nilai |
|---|---|
| Kartu | Sama dengan [section.md](section.md), padding 20–24px |
| Label | 13px / 500, `text-muted` |
| Nilai | 28px / 600, `tabular-nums` |
| Deskripsi | 12px; naik = success, turun = danger, netral = muted |
| Grid | 4 kolom ≥ lg, 2 kolom md, 1 kolom mobile |

## Perilaku & state

- Semua angka dihitung oleh `ReportService` (bukan query di widget) agar sama dengan API laporan.
- Polling: nonaktif secara default (`$pollingInterval = null`) — shared hosting. Refresh manual.
- **Tidak lazy** (`protected static bool $isLazy = false`): widget lazy = 1 request HTTP per widget.
- Belum di-cache (query agregat ringan untuk volume MVP). Bila beranda melambat, cache per tenant 60 detik (driver `database`) di `ReportService`.
- Data kosong: tampilkan `Rp0` / `0`, bukan widget kosong.

## Aksesibilitas

Deskripsi tren menyebut arah dalam teks ("naik 12%"), tidak hanya ikon panah/warna.

## Implementasi

```php
final class SalesTodayWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $summary = app(ReportService::class)->todaySummary(TenantContext::outlet());

        return [
            Stat::make('Omzet hari ini', Money::format($summary->revenue))
                ->description($summary->revenueTrendLabel())   // "Naik 12% dari kemarin"
                ->descriptionIcon($summary->revenueTrendIcon())
                ->color($summary->revenueTrendColor()),
            Stat::make('Transaksi', number_format($summary->orderCount, 0, ',', '.')),
        ];
    }
}
```

## Do / Don't

| Do | Don't |
|---|---|
| Maks. 4 stat per baris | 8 stat kecil berdesakan |
| Angka dari `ReportService` | Query agregasi langsung di widget |
| Polling mati di shared hosting | `pollingInterval = '5s'` |
