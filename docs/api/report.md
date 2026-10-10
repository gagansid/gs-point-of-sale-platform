# API — Laporan

Token user dengan permission `report.view` (owner, manager). Semua angka berasal dari
`App\Services\Report\ReportService` — sama persis dengan beranda dashboard, halaman Laporan,
dan file Excel.

| Method | Endpoint | Isi |
|---|---|---|
| GET | `/reports/summary?from=&to=` | Ringkasan + seri harian |
| GET | `/reports/products?from=&to=` | Per produk, urut omzet terbesar |
| GET | `/reports/payment-methods?from=&to=` | Per metode bayar |

## Aturan

- `from`, `to`: `YYYY-MM-DD` **tanggal lokal outlet**, inklusif. Default hari ini; `to` default = `from`.
  Maks. 366 hari; `to` < `from` → `422`.
- **Omzet diakui saat order selesai** (`completed_at`), bukan saat dibuat (open bill kemarin yang
  dilunasi hari ini = omzet hari ini).
- Order **void** tidak masuk omzet; dilaporkan terpisah (`void_count`, `void_total`, berdasarkan `voided_at`).
- Per metode bayar: hanya pembayaran `paid` pada order selesai.
- `period` di setiap respons: `{ from, to, timezone }`.

## `GET /v1/reports/summary`

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "period": { "from": "2026-10-08", "to": "2026-10-08", "timezone": "Asia/Jakarta" },
    "order_count": 2,
    "revenue": "83900.00",
    "average": "41950.00",
    "items_sold": 3,
    "discount_total": "0.00",
    "service_total": "3600.00",
    "tax_total": "8316.00",
    "rounding_total": "-16.00",
    "void_count": 1,
    "void_total": "28000.00",
    "daily": [{ "date": "2026-10-08", "revenue": "83900.00", "order_count": 2 }]
  },
  "meta": { "request_id": "…" }
}
```

## `GET /v1/reports/products`

`data.products[]`: `{ product_id, product_name (snapshot transaksi terakhir), qty, revenue }`.

## `GET /v1/reports/payment-methods`

`data.payment_methods[]`: `{ payment_method_id, name, category, count, amount }`.

## Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 403 | `FORBIDDEN` | Tanpa `report.view` (supervisor, kasir) |
| 422 | `VALIDATION_ERROR` | Format tanggal salah, `to` < `from`, rentang > 366 hari |

Test: `tests/Feature/Api/V1/Report/ReportTest.php` (termasuk batas hari 23.30 / 00.30 WIB dan isolasi tenant).

## Export Excel (dashboard)

Menu **Laporan → Export Excel** (permission `report.export`): satu file `.xlsx` berisi sheet
Ringkasan, Harian, Per Produk, Per Metode Bayar. Nominal disimpan sebagai angka (bisa dijumlah di
Excel). Teks dari pengguna yang diawali `=`, `+`, `-`, `@` diberi awalan `'` untuk mencegah
**formula injection** (`App\Exports\SafeCell`).
