# API — Setelan

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user** + header `X-App-Version`. Base URL: [README](README.md).

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/v1/outlet` | `outlet.settings` (role; tetap bisa saat hanya-baca) | baca | — |
| PUT | `/v1/outlet` | `outlet.settings` | ya | `UpdateOutletSettings` |

Error umum: `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `403 SUBSCRIPTION_EXPIRED` (PUT saat trial/langganan
habis), `403 TENANT_SUSPENDED`, `422 VALIDATION_ERROR`, `426 APP_UPDATE_REQUIRED`.

---

## `GET /v1/outlet` · `PUT /v1/outlet`

MVP satu outlet per bisnis: endpoint selalu memakai outlet tenant pengguna (tidak ada `{id}`).

| Field | Tipe | Aturan |
|---|---|---|
| `name` | string | maks. 100 |
| `address` | string\|null | maks. 500 |
| `timezone` | string | `Asia/Jakarta`, `Asia/Makassar`, `Asia/Jayapura` (ADR 0001) |
| `tax_rate` | desimal | 0–100, maks. 2 desimal (persen) |
| `tax_inclusive` | bool | `true` = harga menu sudah termasuk pajak |
| `service_charge_rate` | desimal | 0–100 (persen) |
| `rounding` | int | `0` (tanpa), `100`, `500`, `1000` — pembulatan grand total ke Rp terdekat |
| `receipt_header`, `receipt_footer` | string\|null | maks. 500 |
| `discount_limits` | object | `{ "cashier": 10, "supervisor": 25 }` persen 0–100, keduanya wajib bila dikirim (Q18) |
| `code` | — | **Tidak bisa diubah** (awalan nomor order); diabaikan bila dikirim |

PUT: field yang tidak dikirim tetap memakai nilai lama. Order lama tidak berubah — setiap order
menyimpan tarif pajak/service/pembulatan saat transaksi.

```json
{
  "success": true,
  "message": "Setelan outlet berhasil disimpan",
  "data": {
    "id": "…", "code": "JKT01", "name": "Kopi Senja Dago", "address": "Jl. Dago 1",
    "timezone": "Asia/Jakarta", "tax_rate": "11.00", "service_charge_rate": "5.00",
    "tax_inclusive": false, "rounding": 100, "receipt_header": "Kopi Senja",
    "receipt_footer": "Terima kasih", "discount_limits": { "cashier": 10, "supervisor": 25 }
  },
  "meta": { "request_id": "…" }
}
```

Dashboard: menu **Pengaturan → Profil outlet** (`app.gspos.id/pengaturan/outlet`), hanya owner.

Test: `tests/Feature/Api/V1/Settings/OutletTest.php`, `tests/Feature/Filament/Dashboard/OutletSettingsTest.php`
