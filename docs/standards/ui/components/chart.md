# Chart

**Tujuan:** memperlihatkan tren atau komposisi yang sulit dibaca dari tabel.

## Kapan dipakai

| Pertanyaan | Jenis chart |
|---|---|
| Bagaimana penjualan dari waktu ke waktu? | Line / bar per hari atau per jam |
| Produk apa yang paling laku? | Bar horizontal (top 10) |
| Komposisi metode bayar? | Bar horizontal bertumpuk 100% **atau** doughnut (maks. 5 irisan) |

**Tidak** dipakai jika hanya 1–2 angka (pakai [stat-widget.md](stat-widget.md)) atau jika user
perlu nilai persis (sertakan tabel di bawah chart).

## Anatomi

Judul (pertanyaan yang dijawab) → filter periode (kanan atas) → area chart → legenda (bawah,
hanya jika > 1 seri).

## Spesifikasi

| Properti | Nilai |
|---|---|
| Kartu | Sama dengan [section.md](section.md) |
| Tinggi | 280px (beranda), 360px (laporan) |
| Seri tunggal | `primary` |
| Banyak kategori | Urutan: primary `#2D4282`, `#066FD1`, `#2FB344`, `#F59F00`, `#8A919E`; sisanya digabung "Lainnya" |
| Grid | Garis horizontal `--border-light`, tanpa garis vertikal |
| Sumbu nominal | Singkatan Indonesia: `Rp1,2 jt`, `Rp850 rb` |
| Tooltip | Nilai lengkap: `Rp1.250.500` |
| Font | Inter 12px, `text-muted` |

Status (success/warning/danger) **tidak** dipakai sebagai warna kategori agar tidak disalahartikan.

## Perilaku & state

- Data dari `ReportService`, sama dengan endpoint `/reports/*`.
- Periode default: 7 hari terakhir (beranda), mengikuti filter halaman (laporan).
- Kosong: teks "Belum ada data pada periode ini" di tengah area chart.
- Animasi mati dengan `animation: { duration: 0 }` — **bukan** `animation: false` (membuat chart.js Filament gagal dirender).
- Sumbu & tooltip berformat Rupiah lewat `getOptions(): RawJs` (lihat `RevenueChartWidget`).

## Aksesibilitas

Sediakan tabel data di bawah chart di halaman Laporan. Jangan mengandalkan warna saja untuk
membedakan seri; urutkan legenda sama dengan urutan visual.

## Implementasi

```php
final class PaymentMethodChart extends ChartWidget
{
    protected static ?string $heading = 'Komposisi metode bayar';
    protected static ?string $maxHeight = '280px';
    protected static ?string $pollingInterval = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = app(ReportService::class)->paymentMethods(TenantContext::outlet(), now()->subDays(6), now());

        return [
            'datasets' => [[
                'data' => $rows->pluck('total')->map(fn ($v) => (float) $v)->all(), // float hanya untuk render chart
                'backgroundColor' => ['#2D4282', '#066FD1', '#2FB344', '#F59F00', '#8A919E'],
            ]],
            'labels' => $rows->pluck('label')->all(),
        ];
    }
}
```

## Do / Don't

| Do | Don't |
|---|---|
| Judul berupa pertanyaan/ringkasan | Judul "Chart 1" |
| ≤ 5 kategori + "Lainnya" | Doughnut 12 irisan |
| Tabel pendamping di halaman laporan | Chart sebagai satu-satunya sumber angka |
