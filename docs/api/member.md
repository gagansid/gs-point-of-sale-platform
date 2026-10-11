# API — Member

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user** + header `X-App-Version`. Base URL: [README](README.md).
Keputusan: [ADR 0013](../adr/0013-member-loyalty-program.md), SPEC Q60–Q67 (tahap M1–M7).

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/v1/members?search=` | `member.view` | baca | — |
| POST | `/v1/members` | `member.create` | `id` opsional | `SaveMember` |
| GET | `/v1/members/{id}` | `member.view` | baca | — |
| GET | `/v1/members/{id}/points` | `member.view` | baca | — |
| GET | `/v1/vouchers/check?code=` | `order.create` | baca | — |
| POST | `/v1/checkout`, PUT `/v1/orders/{id}` (+ `member_id`) | `order.create` | ya | `ResolveOrderMember` |

Error umum: `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `403 SUBSCRIPTION_EXPIRED`, `404 MEMBER_NOT_FOUND`,
`422 MEMBER_PROGRAM_DISABLED` (program belum diaktifkan owner di **Pengaturan → Program member**),
`422 VALIDATION_ERROR`, `422 VOUCHER_INVALID`, `429 TOO_MANY_REQUESTS`.

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
      "points_balance": 0, "tier": null, "visit_count": 0, "last_visit_at": null, "joined_at": "2026-10-11T10:00:00Z"
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

## Tukar poin & diskon member (M3, SPEC Q62–Q63)

- Program aktif membuat metode bayar sistem **Poin** (`category: points`) — ikut `/catalog`; tampilkan hanya
  bila ada member terpasang. Program dimatikan → metode nonaktif.
- Bayar dengan poin di `POST /checkout` / `POST /orders/{id}/payments`: pembayaran `payment_method_id` = Poin,
  `amount` kelipatan nilai poin (bawaan Rp100 = 1 poin), plus bukti pemilik **`member_code`** (6 digit, kode QR
  halaman member, berlaku ±2 menit, sekali pakai) **atau `member_pin`** (4 digit).
- Ditolak: tanpa member → `422 VALIDATION_ERROR` (`member_id`); tanpa/salah bukti → `422 INVALID_MEMBER_PROOF`;
  PIN salah 5× → `423 MEMBER_PIN_LOCKED` (15 menit); saldo kurang, di bawah minimal, di atas maksimal persen
  tagihan, atau bukan kelipatan → `422 INSUFFICIENT_POINTS`.
- Pajak dihitung dari harga penuh; pembayaran Poin tidak masuk kas laci, tanpa kembalian. Bagian yang dibayar poin
  tidak menghasilkan poin. Void → poin dikembalikan. Order: `points_redeemed`.
- **Diskon member** (% di Program member) otomatis untuk order ber-member di outlet yang ikut program, setelah diskon
  kasir, **tidak** dihitung ke batas diskon kasir. Order/struk: `member_discount` (bagian dari `discount_total`).

## Halaman member (M4, SPEC Q67) — web, bukan API

`gspos.id/m/{kode-bisnis}` (tanpa subdomain: `/m/{kode-bisnis}`), hanya bila program member aktif (selain itu 404):
masuk (nomor HP + kata sandi, pesan umum, 5/menit per HP+IP, 20/menit per IP, kunci 15 menit setelah 10 salah),
daftar mandiri (persetujuan wajib), beranda dengan **QR dinamis** `KODEMEMBER-123456` (kode bukti berganti tiap 30
detik, berlaku ±2 menit, sekali pakai), riwayat poin & transaksi, profil (HP tidak bisa diganti sendiri), ganti kata
sandi (sesi lain keluar), hapus akun (dianonimkan).

**Aplikasi kasir (Flutter):** scan QR → string `KODEMEMBER-123456`: cari member dengan bagian kode (`search`), kirim
6 digit terakhir sebagai `member_code` saat membayar dengan poin. Lupa kata sandi: owner/manager membuat kata sandi
sementara di dashboard (Member → Kata sandi sementara); member wajib menggantinya saat masuk.

## Tier (M5, SPEC Q64)

- Owner mengatur tier di **Pengaturan → Program member** (nama, minimal belanja 12 bulan, pengali poin, diskon
  tambahan). Member masuk tier tertinggi yang batasnya terpenuhi; di bawah tier terendah = tanpa tier.
- Belanja 12 bulan = total `grand_total` order selesai (void tidak dihitung) 12 bulan terakhir; dievaluasi saat order
  selesai/void dan setiap hari (tier bisa turun).
- Pengali poin: `floor(floor(dasar / earn_amount) × earn_points × pengali)`, memakai tier **sebelum** order ini.
- Diskon tier **ditambahkan** ke diskon member dasar (`member_discount`, maks. 100%), tidak dihitung ke batas diskon kasir.
- Member (`GET /members`, `/members/{id}`): `tier: { id, name, point_multiplier, discount_percent }` atau `null`.
- Order: `member: { id, name, tier }` (snapshot nama tier yang diskonnya dipakai); struk: `member_tier`.

## Kartu stempel (M6, SPEC Q65)

- Owner membuat program di **Pelanggan → Kartu stempel** (nama, stempel untuk 1 gratis, produk/kategori).
- `GET /v1/members/{id}` memuat `stamp_cards`:

```json
"stamp_cards": [
  { "id": "…", "name": "Kopi ke-10 gratis", "stamps_required": 10, "balance": 12, "rewards_available": 1,
    "product_ids": ["…"], "category_ids": ["…"] }
]
```

- Tukar: kirim `stamp_program_ids: ["…"]` di `POST /checkout` / `PUT /orders/{id}` (maks. 5, 1 gratis per kartu).
  Server memilih item **termurah** yang memenuhi (harga satuan setelah diskon item) dan mencatatnya sebagai
  `stamp_free_total` (bagian `discount_total`, sebelum diskon order manual). Order: `stamp_redemptions`
  (`stamp_program_id`, `name`, `product_id`, `product_name`, `amount`), `stamps_earned`.
- Ditolak `422 VALIDATION_ERROR` field `stamp_program_ids`: tanpa member, outlet dikecualikan, kartu nonaktif/bisnis
  lain, stempel kurang, atau tidak ada item yang memenuhi. Saldo dicek ulang saat lunas (open bill).
- Stempel didapat saat order selesai: 1 item = 1 stempel per kartu; unit yang digratiskan tidak dihitung.
  Void: stempel didapat ditarik, stempel yang ditukar dikembalikan. Struk: `totals.stamp_free_total`,
  `member_stamps: [{ name, earned, balance, stamps_required }]`.

## Voucher (M7, SPEC Q66)

- **Kode promo** dibuat owner di **Pelanggan → Voucher**: nominal/persen (+ maks. potongan), minimal belanja
  (subtotal setelah diskon item), kuota total & per member, periode, khusus member/tier, outlet.
- **Voucher ulang tahun** (Pengaturan → Program member): dibuat otomatis untuk member di bulan lahirnya (WIB),
  berlaku sampai akhir bulan, sekali pakai. Muncul di `GET /v1/members/{id}` → `vouchers` dan halaman member.
- Semua voucher hanya berlaku di outlet yang ikut program member. Kode promo tanpa syarat member boleh dipakai
  tanpa member.
- Pakai: `voucher_code` (kode promo) **atau** `voucher_id` (voucher pribadi) di `POST /checkout` /
  `PUT /orders/{id}`. Potongan `voucher_discount` (bagian `discount_total`) dihitung setelah diskon order manual,
  sebelum diskon member, tidak dihitung ke batas diskon kasir. Order: `voucher: { id, code }`; struk:
  `totals.voucher_code`, `totals.voucher_discount`.
- Pemakaian & kuota dicatat saat order **selesai** (dicek ulang; open bill yang kuotanya keburu habis ditolak saat
  dibayar). Void mengembalikan kuota.

### `GET /v1/vouchers/check?code=HEMAT10&member_id=&subtotal=`

Cek kode untuk outlet perangkat sebelum checkout (pratinjau). `member_id` & `subtotal` opsional (tanpa `subtotal`
minimal belanja tidak dicek). Maks. 30 cek/menit per user.

```json
{ "data": { "id": "…", "code": "HEMAT10", "name": "Promo akhir pekan", "source": "owner", "type": "percent",
  "value": "10.00", "max_discount": "20000.00", "min_spend": "50000.00", "member_only": false,
  "ends_at": "2026-10-31T16:59:59Z" } }
```

`422 VOUCHER_INVALID` → `error.details.reason`: `not_found`, `inactive`, `not_started`, `expired`,
`quota_exhausted`, `member_quota_exhausted`, `min_spend` (+ `min_spend`), `member_only`, `tier`, `outlet`,
`not_owner`.
