# Dokumentasi Endpoint

Satu file per domain, berisi semua endpoint domain tersebut dengan format
[`docs/templates/endpoint.md`](../templates/endpoint.md). Standar teknis: [`docs/standards/api/`](../standards/api/README.md).

| Domain | File | Status |
|---|---|---|
| Auth & sistem | [`auth.md`](auth.md) | ✅ 7 endpoint |
| Katalog & produk | [`product.md`](product.md) | ✅ 13 endpoint |
| Shift | [`shift.md`](shift.md) | ✅ 5 endpoint |
| Order & pembayaran | [`order.md`](order.md) | ✅ 7 endpoint |
| Laporan | [`report.md`](report.md) | ✅ 3 endpoint + export Excel di dashboard |
| Setelan (outlet, karyawan, metode bayar) | `settings.md` | Belum |

File dibuat bersamaan dengan endpoint pertama di domain tersebut.

**Base URL (ADR 0008):** `https://api.gspos.id` + path di dokumen ini (mis. `GET /v1/catalog` →
`https://api.gspos.id/v1/catalog`). Lokal: `http://api.gspos.localhost:8000/v1/...`. Instalasi tanpa
subdomain (`POS_API_DOMAIN` kosong) memakai `/api/v1/...` di domain utama; URL lama `/api/v1/...` di
domain utama dialihkan 308 ke `api.gspos.id/v1/...` (metode & body tetap).
