# API — Laporan

Token user. Semua angka berasal dari `App\Services\Report\ReportService` — sama persis dengan
beranda dashboard, halaman Laporan, dan file Excel/PDF.

## Akses (SPEC Q49)

Fitur paket (ADR 0025): `profit`, `options`, `employees`, `heatmap` butuh fitur `advanced_reports`; `members` butuh
`member`; `purchases` butuh `stock` → tanpa fitur `403 FEATURE_NOT_AVAILABLE` (`error.details.feature`). Lihat
`tenant.features` di `auth/me`. Endpoint lain (termasuk `shifts/{id}/pdf`) tidak dibatasi paket.

| Permission | Role | Endpoint |
|---|---|---|
| `report.view` | owner, manager | Semua, periode maks. 366 hari per query |
| `report.view_shift` | + supervisor | `summary`, `trend`, `products`, `payment-methods`, `employees`, `shifts`, `audit` — `from` maks. **31 hari ke belakang** dari hari ini (lebih lama → `422`) |
| `report.view_own` | + kasir | `shifts/{id}` untuk shift yang pernah dipegang (pembuka atau operator, ADR 0017) — X/Z-report |

| Method | Endpoint | Izin | Isi |
|---|---|---|---|
| GET | `/reports/summary` | view / view_shift | Ringkasan + seri harian (`subtotal_total`, `net_sales` = omzet − pajak − service − pembulatan) |
| GET | `/reports/trend?granularity=` | view / view_shift | Tren per `hour` (1 hari) / `day` / `week` (Senin) / `month` + `previous_trend` |
| GET | `/reports/products` | view / view_shift | Per produk, urut omzet terbesar |
| GET | `/reports/payment-methods` | view / view_shift | Per metode bayar |
| GET | `/reports/employees` | view / view_shift | Per kasir: transaksi, omzet, rata-rata, diskon, void |
| GET | `/reports/shifts` | view / view_shift | Shift yang dibuka pada rentang: kas awal, tunai, `cash_in`, `cash_out`, kas seharusnya vs aktual, selisih, `cash_deposited` |
| GET | `/reports/shifts/{id}` | view / view_shift (≤ 31 hari) / view_own (pembuka/operator) | Rekap satu shift: `type` `x_report` (berjalan) / `z_report` (ditutup), penjualan, kas (`carried_cash`, `cash_in`, `cash_out`, `cash_deposited`, `closing_float`), `shift.opening_cash_source` + koreksinya, `operators` (jam bertugas, transaksi, omzet, tunai per kasir), `cash_movements`, metode bayar, produk (ADR 0017) |
| GET | `/reports/shifts/{id}/pdf` | sama dengan `shifts/{id}` | PDF rekap shift (`application/pdf`), maks. 10 export/menit per user |
| GET | `/reports/audit` | view / view_shift | Void, diskon dengan approval PIN, shift selisih kas / ditutup paksa / kas awal dikoreksi (`opening_adjustment`), kas keluar (`cash_out_count`, `cash_out_total`, `cash_outs`) — 20 terbaru + jumlah |
| GET | `/reports/categories` | view | Per kategori dari snapshot `order_items.category_name` (Q51) |
| GET | `/reports/channels` | view | Per kanal (ADR 0014): `channels[]` `{ channel (pos/self_order), label, order_count, revenue, online_total }` |
| GET | `/reports/members` | view | Member (ADR 0013, M7): `summary` (member vs non-member: transaksi & omzet, `member_share` %, member aktif/berulang ≥ 2 kali/baru (outlet daftar), diskon member, poin didapat/ditukar/kedaluwarsa, **utang poin** saat ini = saldo aktif × nilai poin (seluruh bisnis), voucher & gratis stempel), `top_members` (20 teratas), `vouchers` (per kode), `tiers` (sebaran saat ini) |
| GET | `/reports/profit` | view | Laba kotor per produk (Q53): `net_sales` (setelah diskon order, tanpa pajak & service), `cogs` (qty × harga modal saat transaksi), `gross_profit`, `margin` (%), `uncosted_qty`; `totals` (margin hanya dari item ber-harga modal) |
| GET | `/reports/options` | view | Opsi/add-on: `qty` (dipilih × qty item), `revenue` (tambahan omzet) |
| GET | `/reports/hourly` | view | Per jam lokal (0–23) |
| GET | `/reports/heatmap` | view | `heatmap[hari ISO 1–7][jam 0–23]` = jumlah transaksi |
| GET | `/reports/stock` | view | Per produk: terjual, kembali dari void, penyesuaian, `purchased` (stok masuk bersih), `opname`, `transfer`, stok kini, `stock_value` (stok × modal), `is_low` |
| GET | `/reports/purchases` | view | Pembelian dari stok masuk diposting (ADR 0015): `suppliers[]` `{ supplier_id, supplier_name, receipt_count, qty, total_cost }`, `products[]` `{ product_id, product_name, qty, average_cost, total_cost }`, `totals` |
| GET | `/reports/dashboard` | view | Ringkasan beranda (KPI + pembanding + tren + metode bayar + produk + tipe order + outlet) |

## Parameter (semua endpoint kecuali `shifts/{id}`)

| Parameter | Nilai |
|---|---|
| `from`, `to` | `YYYY-MM-DD` tanggal lokal outlet, inklusif |
| `outlet_id` | UUID outlet yang dipegang / `all` |
| `compare` | `previous_period` (bawaan) / `previous_year` / `none` — supervisor: pembanding di luar 31 hari = `null` |
| `granularity` | `hour` / `day` / `week` / `month`; tidak cocok dengan rentang → bawaan (≤ 1 hari jam, ≤ 62 hari hari, ≤ 180 hari minggu, sisanya bulan) |
| `order_type` | `takeaway` / `dine_in` |
| `payment_method_ids[]`, `user_ids[]`, `category_ids[]`, `product_ids[]`, `shift_ids[]` | UUID; ID tenant/outlet lain = hasil kosong (fail-closed) |

## Aturan

- `from`, `to`: `YYYY-MM-DD` **tanggal lokal outlet**, inklusif. Default hari ini; `to` default = `from`.
  Maks. 366 hari; `to` < `from` → `422`.
- **Omzet diakui saat order selesai** (`completed_at`), bukan saat dibuat (open bill kemarin yang
  dilunasi hari ini = omzet hari ini).
- Order **void** tidak masuk omzet; dilaporkan terpisah (`void_count`, `void_total`, berdasarkan `voided_at`).
- Per metode bayar: hanya pembayaran `paid` pada order selesai.
- `outlet_id` (opsional, ADR 0010): satu outlet yang dipegang user atau `all` (semua outlet yang dipegang).
  Tanpa `outlet_id`: outlet perangkat bila login di perangkat, selain itu semua outlet yang dipegang.
  Outlet di luar penugasan → `404 NOT_FOUND`. Zona waktu tanggal mengikuti outlet terpilih (atau outlet pertama).
- `period` di setiap respons: `{ from, to, timezone, outlet_id }` (`outlet_id` `null` = semua outlet).

## `GET /v1/reports/summary`

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "period": { "from": "2026-10-08", "to": "2026-10-08", "timezone": "Asia/Jakarta", "outlet_id": null },
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
| 403 | `FORBIDDEN` | Tanpa izin endpoint (lihat tabel akses); shift orang lain untuk kasir |
| 404 | `NOT_FOUND` | `outlet_id` / shift di luar outlet yang dipegang user |
| 422 | `VALIDATION_ERROR` | Format tanggal/UUID salah, `to` < `from`, rentang > 366 hari, supervisor `from` > 31 hari ke belakang, `granularity` tidak dikenal |
| 429 | `TOO_MANY_REQUESTS` | `shifts/{id}/pdf` lebih dari 10 export/menit per user (bersama export dashboard) |

Test: `tests/Feature/Api/V1/Report/ReportTest.php`, `ReportModuleApiTest.php`, `tests/Feature/Report/ReportServiceTest.php`
(batas hari 23.30 / 00.30 WIB, minggu Senin, bulan, kabisat, isolasi tenant, batas supervisor, rekap shift kasir).

## Export (dashboard & API) — Q52, ADR 0012

Menu **Laporan → Export** (permission `report.export`: owner, manager, supervisor) — grup aksi gray:

| Aksi | Isi | Nama file |
|---|---|---|
| Excel — tab ini / lengkap | Sheet **Info** (outlet, periode, pembanding, filter aktif, dicetak oleh, waktu) + satu sheet per bagian | `laporan-{tab\|lengkap}-{from}-{to}.xlsx` |
| PDF — tab ini / lengkap | Kop gs.POS + bisnis/outlet, info filter, 4 stat, satu tabel per bagian, nomor halaman (landscape bila tabel lebar) | `laporan-{tab\|lengkap}-{from}-{to}.pdf` |

- Filter & batas periode sama dengan layar; "lengkap" = semua tab yang boleh dilihat user (supervisor
  tidak pernah mendapat Kategori/Opsi/Waktu/Tipe order/Outlet/Stok).
- Sinkron (tanpa queue). Maks. 50.000 baris (Excel) / 5.000 baris (PDF) per file; lebih → ditolak dengan
  "Data terlalu besar untuk diekspor. Persempit rentang tanggal." (tidak dipotong diam-diam).
- Excel: nominal sebagai angka; teks pengguna diawali `= + - @` diberi awalan `'` (`SafeCell`, anti
  formula injection). PDF: teks di-escape Blade, dompdf `enable_php`/`enable_remote`/`enable_javascript` = false.
- Beranda tetap punya **Export Excel** ringkas (Ringkasan, Harian, Per Produk, Per Metode Bayar).

API: `GET /reports/shifts/{id}/pdf` — PDF rekap shift (X/Z-report), izin sama dengan `shifts/{id}`
(pemilik shift termasuk kasir). Respons `application/pdf`. Maks. 10 export/menit per user
(bersama export dashboard) → 429 `TOO_MANY_REQUESTS`.
