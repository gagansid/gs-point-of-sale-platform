# API — Setelan

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user** + header `X-App-Version`. Base URL: [README](README.md).

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/v1/outlet` | `outlet.settings` (role; tetap bisa saat hanya-baca) | baca | — |
| PUT | `/v1/outlet` | `outlet.settings` | ya | `UpdateOutletSettings` |
| GET | `/v1/users` | `user.manage` (role) | baca | — |
| GET | `/v1/users/{id}` | `user.manage` (role) | baca | — |
| POST | `/v1/users` | `user.manage` | `id` opsional | `SaveEmployee` |
| PUT | `/v1/users/{id}` | `user.manage` | ya | `SaveEmployee`, `SetEmployeeActive` |
| POST | `/v1/users/{id}/unlock-pin` | `user.manage` | ya | `UnlockEmployeePin` |

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

Dashboard: menu **Pengaturan → Profil outlet** (`app.gspos.id/settings/outlet`), hanya owner.

Test: `tests/Feature/Api/V1/Settings/OutletTest.php`, `tests/Feature/Filament/Dashboard/OutletSettingsTest.php`

---

## Karyawan — `/v1/users`

| Field | Tipe | Aturan |
|---|---|---|
| `id` | uuid | Hanya POST; idempotency key |
| `name` | string | maks. 100; tampil di layar pilih kasir & struk |
| `role` | enum | `owner`, `manager`, `supervisor`, `cashier` |
| `email` | string\|null | Wajib untuk owner/manager (login email); unik global, disimpan huruf kecil. Kasir/supervisor boleh kosong |
| `username` | string\|null | Wajib untuk supervisor/kasir (kasir web, Q35); 3–30, huruf/angka/`._-`, disimpan huruf kecil, unik per bisnis |
| `password` | string | Wajib saat menambah (semua role); `Password::defaults()` (min. 8, huruf & angka). PUT: kosong = tidak diganti |
| `pin` | string | 6 digit, **bukan** angka sama (`111111`) atau berurutan (`123456`, `654321`). Wajib untuk supervisor/kasir (login tablet), opsional untuk owner/manager (approval). PUT: kosong = tidak diganti |
| `is_active` | bool | Hanya PUT. `false` = tidak bisa login & semua sesi diputus |

Respons (`EmployeeResource`) **tidak pernah** berisi PIN/kata sandi: `has_pin`, `pin_locked_until`,
`last_login_at`, `is_active`, `role_label`, `username`, `has_password`, `outlet_id` (owner `null`, role lain = outlet bisnis).

Aturan:

- Ganti PIN / kata sandi / role, atau nonaktifkan → **semua token karyawan itu dihapus** (tablet & app
  langsung keluar). Ganti PIN juga membuka kunci PIN.
- Owner aktif terakhir tidak bisa diturunkan rolenya atau dinonaktifkan → `409 LAST_OWNER_REQUIRED`.
- Tidak bisa menonaktifkan akun sendiri → `422 VALIDATION_ERROR` (`details.is_active`).
- Karyawan tidak dihapus (riwayat transaksi tetap utuh), hanya dinonaktifkan.
- `GET /v1/users`: filter `search` (nama/email), `role`, `is_active`; pagination `page`, `per_page`.

Dashboard: **Pengaturan → Karyawan** (`app.gspos.id/settings/employees`), hanya owner.

Test: `tests/Feature/Api/V1/Settings/EmployeeTest.php`, `tests/Feature/Filament/Dashboard/EmployeeResourceTest.php`,
`tests/Unit/SecurePinTest.php`
