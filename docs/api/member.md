# API — Member

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user** + header `X-App-Version`. Base URL: [README](README.md).
Keputusan: [ADR 0013](../adr/0013-member-loyalty-program.md), SPEC Q60–Q61 (tahap M1).

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/v1/members?search=` | `member.view` | baca | — |
| POST | `/v1/members` | `member.create` | `id` opsional | `SaveMember` |
| GET | `/v1/members/{id}` | `member.view` | baca | — |
| GET | `/v1/members/{id}/points` | `member.view` | baca | — |
| POST | `/v1/checkout`, PUT `/v1/orders/{id}` (+ `member_id`) | `order.create` | ya | `ResolveOrderMember` |

Error umum: `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `403 SUBSCRIPTION_EXPIRED`, `404 MEMBER_NOT_FOUND`,
`422 MEMBER_PROGRAM_DISABLED` (program belum diaktifkan owner di **Pengaturan → Program member**),
`422 VALIDATION_ERROR`, `429 TOO_MANY_REQUESTS`.

Member milik **bisnis** dan berlaku di semua outlet. Owner bisa mengecualikan outlet; di outlet itu member tetap
bisa dipasang (tercatat kunjungan) tanpa benefit. Nomor HP disamarkan untuk kasir (`phone_masked`); field
pribadi lengkap (`phone`, `email`, `birth_day`, `birth_month`, `notes`) hanya untuk `member.manage`.

---

## `GET /v1/members?search=`

`search` (2–100 karakter): nomor HP format apa pun (`0812…`, `+62 812-…`), kode member (`RKX7P2`), nama, atau
4+ digit terakhir HP. Maks. 20 hasil, urut kunjungan terakhir. Maks. **30 pencarian/menit per user**
(`429 TOO_MANY_REQUESTS`) — mencegah menebak nomor HP.

```json
{
  "success": true,
  "message": "Daftar member",
  "data": [
    {
      "id": "…", "code": "RKX7P2", "name": "Rina Kusuma", "phone_masked": "•••• 7890", "is_active": true,
      "points_balance": 0, "visit_count": 0, "last_visit_at": null, "joined_at": "2026-10-11T10:00:00Z"
    }
  ],
  "meta": { "request_id": "…" }
}
```

## `POST /v1/members`

Daftar cepat dari kasir. Outlet perangkat dicatat sebagai outlet asal.

| Field | Aturan |
|---|---|
| `id` | UUID dari app, opsional — idempotency key (`meta.idempotent_replay`) |
| `name` | wajib, maks. 100 |
| `phone` | wajib; dinormalisasi ke `+62…` (nomor HP Indonesia); **unik per bisnis** → `422` field `phone` "Nomor HP sudah terdaftar sebagai member" |
| `consent` | wajib `true` — pelanggan menyetujui datanya dipakai untuk program member (UU PDP) |
| `email` | opsional |
| `birth_day`, `birth_month` | opsional (tanpa tahun); `birth_month` wajib bila `birth_day` diisi; tanggal harus valid |
| `notes` | opsional, maks. 255 |

Respons `201` (baru) / `200` (replay) berisi member seperti di atas.

## `GET /v1/members/{id}`

Detail member. Member tenant lain, dihapus (dianonimkan) → `404 MEMBER_NOT_FOUND`.

## Member di transaksi

`POST /v1/checkout` dan `PUT /v1/orders/{id}` menerima `member_id` (UUID, opsional):

- Member harus aktif & milik bisnis ini → selain itu `404 MEMBER_NOT_FOUND` (order tidak dibuat).
- Program nonaktif → `422 MEMBER_PROGRAM_DISABLED`.
- Open bill: **field tidak dikirim = member lama tetap** (aplikasi lama aman); `null` = member dilepas.
- Respons order: `member: { id, name }` (snapshot nama saat transaksi) atau `null`; struk: `member_name`.

## `GET /v1/members/{id}/points`

Saldo + 50 mutasi poin terbaru (`earn`, `redeem`, `void_reversal`, `adjust`, `expire`):

```json
{ "data": { "balance": 120, "entries": [
  { "id": "…", "type": "earn", "type_label": "Didapat", "points": 7, "balance_after": 120,
    "order_id": "…", "expires_at": "2027-10-11T10:00:00Z", "reason": null, "created_at": "2026-10-11T10:00:00Z" }
] } }
```

## Poin (M2, SPEC Q62)

- Didapat saat order **selesai**: `floor(dasar / earn_amount) × earn_points`; dasar = subtotal setelah diskon item &
  order, **tanpa** service, pajak, pembulatan. Bawaan Rp10.000 = 1 poin, berlaku 12 bulan (diatur owner).
- Order: `points_earned`; struk: `member_points: { earned, balance }`.
- Void order selesai: poin ditarik (saldo boleh minus bila sudah terpakai).
- Outlet dikecualikan: kunjungan tercatat, tanpa poin.

Penukaran poin (metode bayar Poin), diskon member, tier, stempel, dan voucher menyusul (ADR 0013, M3–M7).
