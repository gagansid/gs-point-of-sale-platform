# Dokumentasi Endpoint

Satu file per domain, berisi semua endpoint domain tersebut dengan format
[`docs/templates/endpoint.md`](../templates/endpoint.md). Standar teknis: [`docs/standards/api/`](../standards/api/README.md).

| Domain | File | Status |
|---|---|---|
| Auth & sistem | [`auth.md`](auth.md) | ✅ 7 endpoint |
| Katalog & produk | [`product.md`](product.md) | ✅ 14 endpoint |
| Shift | [`shift.md`](shift.md) | ✅ 5 endpoint |
| Order & pembayaran | [`order.md`](order.md) | ✅ 7 endpoint |
| Member | [`member.md`](member.md) | ✅ 5 endpoint (cari, daftar, detail, riwayat poin, cek voucher) |
| Self-order (QR meja) | [`self-order.md`](self-order.md) | ✅ 3 endpoint (pesanan masuk, terima, tolak) + webhook Midtrans |
| Laporan | [`report.md`](report.md) | ✅ 19 endpoint (termasuk PDF rekap shift, laba kotor, member, kanal, pembelian) + export Excel/PDF di dashboard |
| Setelan (outlet, karyawan, metode bayar) | [`settings.md`](settings.md) | 🟡 10 endpoint (outlet, daftar outlet, karyawan, metode bayar); perangkat menyusul |

**Total: 70 endpoint** (cek: `php artisan scramble:analyze`). Dokumentasi interaktif (Scramble) dibuat
otomatis dari route & docblock controller — tidak perlu diperbarui manual; cukup jaga docblock tetap akurat.

File dibuat bersamaan dengan endpoint pertama di domain tersebut.

**Base URL (ADR 0008):** `https://api.gspos.id` + path di dokumen ini (mis. `GET /v1/catalog` →
`https://api.gspos.id/v1/catalog`). Lokal: `http://api.gspos.localhost:8000/v1/...`. Instalasi tanpa
subdomain (`POS_API_DOMAIN` kosong) memakai `/api/v1/...` di domain utama; URL lama `/api/v1/...` di
domain utama dialihkan 308 ke `api.gspos.id/v1/...` (metode & body tetap).
