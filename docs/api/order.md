# API — Order & pembayaran

Endpoint tulis (checkout, open bill, bayar) butuh token user terikat device kasir + shift terbuka.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| POST | `/checkout` | `order.create` | `id` order & `payments[].id` | `CheckoutOrder` |
| PUT | `/orders/{id}` | `order.create` | upsert per `{id}` | `SaveOpenBill` |
| POST | `/orders/{id}/payments` | `order.create` | `payments[].id` | `AddPayment` |
| GET | `/orders` | `order.view_own` / `order.view_all` | baca | `VisibleOrders` |
| GET | `/orders/{id}` | idem | baca | `OrderPolicy::view` |
| GET | `/orders/{id}/receipt` | `order.reprint` + visibilitas | baca | `ReceiptResource` |
| POST | `/orders/{id}/void` | `order.void` atau PIN approver 🔑 | void ulang = hasil sama | `VoidOrder` |
| POST | `/orders/{id}/refunds` | `order.refund` atau PIN approver 🔑 | ya: `id` dari aplikasi | `RefundOrder` |
| GET | `/refundable-orders/{nomor}` | `order.create` | baca | `RefundableOrders` |
| POST | `/orders/{id}/split` | `order.create` | ya: `new_order_id` | `SplitOpenBill` |
| POST | `/orders/{id}/merge` | `order.create` | ya: gabung ulang = hasil sama | `MergeOpenBills` |
| POST | `/orders/quote` | `order.create` | baca | `QuoteOrder` |

Komponen bersama (aturan identik di semua alur): `ResolveOrderLines` (item & opsi), `PriceOrder`
(kalkulator + batas diskon), `AllocatePayments` (alokasi & kembalian), `OrderWriter` (item, pembayaran,
penyelesaian, stok).

## `POST /v1/checkout`

Buat order + bayar sekaligus. **Server menghitung ulang semua nominal**; field nominal tagihan
yang dikirim aplikasi diabaikan.

### Request

```json
{
  "id": "0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a",
  "order_type": "takeaway",
  "table_label": null,
  "notes": null,
  "discount": { "type": "fixed", "value": 5000 },
  "items": [
    { "product_id": "…", "qty": 2, "option_ids": ["…large"], "notes": "less ice", "discount": 0 }
  ],
  "payments": [
    { "id": "…", "payment_method_id": "…tunai", "amount": 50000, "tendered": 50000 },
    { "id": "…", "payment_method_id": "…debit", "amount": 40900, "reference": "A12345" }
  ],
  "approver_user_id": null,
  "approver_pin": null
}
```

| Field | Aturan |
|---|---|
| `id` | UUID v7 dari app, wajib — idempotency key |
| `order_type` | `takeaway` / `dine_in` |
| `discount` | opsional; `type` `fixed`/`percent` (≤ 100), `value` ≥ 0 |
| `items` | 1–100; `qty` 1–999; `option_ids` maks. 20; `discount` per item (nominal) |
| `payments` | wajib ada, 0–10; `[]` hanya bila total akhir 0 (mis. diskon 100%), selain itu `422` pembayaran kurang; `id` UUID unik; `amount` > 0; `tendered` untuk tunai; `reference` wajib bila metode `requires_reference` |
| `approver_user_id` + `approver_pin` | hanya bila diskon di atas batas role (PIN 6 digit) |
| `member_id` | opsional (ADR 0013): member aktif bisnis ini, selain itu `404 MEMBER_NOT_FOUND`; program nonaktif `422 MEMBER_PROGRAM_DISABLED`. Open bill: tidak dikirim = tetap, `null` = dilepas. Lihat [`member.md`](member.md) |
| `stamp_program_ids` | opsional (M6): kartu stempel yang ditukar, maks. 5 UUID unik; 1 item termurah yang memenuhi per kartu jadi gratis. Butuh member. Open bill: tidak dikirim = pilihan lama tetap. Gagal → `422 VALIDATION_ERROR` field `stamp_program_ids` |
| `voucher_code` / `voucher_id` | opsional (M7), salah satu: kode promo (tidak peka huruf besar) atau ID voucher pribadi dari `GET /members/{id}` → `vouchers`. Open bill: tidak dikirim = voucher lama tetap, `null` = dilepas. Tidak berlaku → `422 VOUCHER_INVALID` (`details.reason`) |

### Aturan

1. **Perhitungan** (`OrderCalculator`, urutan SPEC): harga item → subtotal → diskon order → service →
   pajak (exclusive/inclusive) → pembulatan ke kelipatan Rp `outlet.rounding` (half-up).
2. **Item:** produk harus aktif & tersedia; opsi harus milik grup opsi produk; jumlah pilihan per grup
   sesuai `min_select`–`max_select`. Pelanggaran → `422` per field (`items.N.product_id`, `items.N.option_ids`).
   Total/baris di atas `9999999999999.99` (kapasitas DECIMAL 15,2) → `422` "Total transaksi melebihi batas" (`items`).
3. **Pembayaran:** non-tunai dialokasikan dulu dan tidak boleh melebihi sisa (`PAYMENT_EXCEEDS_BALANCE`,
   `error.details.remaining`); tunai menutup sisa, kelebihannya `change`. Total kurang → `422`
   (`error.details.remaining`).
4. **Diskon:** persen total diskon terhadap harga kotor dibandingkan `outlet.discount_limits[role]`
   (kasir & supervisor; owner/manager dengan `order.discount_over_limit` tanpa batas).
   Di atas batas → `422 DISCOUNT_OVER_LIMIT` (`details.limit_percent` = batas pelaku,
   `details.discount_percent`), kecuali disertai PIN approver yang batasnya mencukupi: supervisor sampai
   batas supervisor, manager/owner tanpa batas; approver yang batasnya kurang → `403 FORBIDDEN`
   (`approved_by` tercatat; approver = pelaku → `SELF_APPROVAL_NOT_ALLOWED`; PIN salah → `INVALID_PIN`,
   ikut penguncian PIN).
5. **Nomor order:** `{kode outlet}-{YYMMDD lokal outlet}-{0001}` dengan penguncian baris.
6. **Stok:** produk `track_stock` dikurangi saat order selesai (boleh minus), riwayat `stock_movements` tipe `sale`.
7. **Snapshot:** nama & harga produk/opsi disimpan di item; perubahan katalog tidak mengubah order lama.

### Respons sukses — `201` (baru) / `200` (replay)

```json
{
  "success": true,
  "message": "Transaksi berhasil disimpan",
  "data": {
    "id": "…", "order_number": "JKT01-261008-0001", "order_type": "takeaway", "status": "completed",
    "discount": null, "subtotal": "78000.00", "order_discount": "0.00", "discount_total": "0.00",
    "service_total": "3900.00", "tax_total": "9009.00", "rounding": "-9.00", "grand_total": "90900.00",
    "paid_total": "90900.00", "change_total": "9100.00", "tax_inclusive": false, "approved_by": null,
    "items": [{ "product_name": "Es Kopi Susu", "unit_price": "22000.00", "options_total": "5000.00", "qty": 2,
                "discount": "0.00", "line_total": "54000.00", "options": [{ "name": "Large", "price_delta": "5000.00" }] }],
    "payments": [{ "category": "cash", "amount": "90900.00", "tendered": "100000.00", "change": "9100.00", "status": "paid" }],
    "created_at": "…Z", "completed_at": "…Z"
  },
  "meta": { "request_id": "…", "idempotent_replay": false }
}
```

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `INVALID_PIN` | PIN approver salah |
| 403 | `DEVICE_NOT_REGISTERED` | Token tidak terikat device kasir |
| 403 | `SELF_APPROVAL_NOT_ALLOWED` / `FORBIDDEN` | Approver = pelaku / approver tanpa wewenang |
| 404 | `NOT_FOUND` | `id` order milik tenant lain |
| 409 | `SHIFT_NOT_OPEN` | Device belum membuka shift |
| 422 | `VALIDATION_ERROR` | Bentuk data, item/opsi tidak valid, reference kosong, pembayaran kurang, `payments[].id` sudah dipakai; produk tidak dijual di outlet (`items.N.product_id`), opsi habis di outlet (`items.N.option_ids`), metode bayar nonaktif di outlet (`payments.N.payment_method_id`) — ADR 0011 |
| 422 | `PAYMENT_EXCEEDS_BALANCE` | Non-tunai melebihi sisa |
| 422 | `DISCOUNT_OVER_LIMIT` | Diskon di atas batas tanpa approval |
| 423 | `PIN_LOCKED` | PIN approver terkunci |

### Diuji

- `tests/Unit/OrderCalculatorTest.php`: semua langkah, inclusive/exclusive, pembulatan.
- `tests/Feature/Api/V1/Order/CheckoutTest.php`: termasuk idempotensi, isolasi tenant, approval.
- **Uji konkurensi manual (MySQL, 8 worker PHP):**
  - 30 checkout paralel menghasilkan 30 nomor unik berurutan tanpa celah, dan stok tepat berkurang 30;
  - 10 request bersamaan dengan `id` sama menghasilkan 1 order (`201` sekali, `200` replay 9 kali).

---

## `PUT /v1/orders/{id}` — open bill (dine-in)

Upsert berdasarkan `{id}` (UUID v7 dari app). Body sama dengan checkout **tanpa** `payments`;
`order_type` default `dine_in`.

- **Item dikirim lengkap** dan menggantikan item lama; total dihitung ulang (aturan diskon sama).
- Nomor order diberikan saat open bill dibuat dan tidak berubah.
- **Stok belum dipotong**; dipotong saat lunas.
- Order selesai/void → `409 ORDER_ALREADY_CLOSED`.
- Total baru < yang sudah dibayar → `422` (`details.items`).
- Respons `201` (dibuat) / `200` (diubah).

## `POST /v1/orders/{id}/payments` — tambah pembayaran

Body `{ "payments": [ { "id", "payment_method_id", "amount", "tendered"?, "reference"? } ] }`.

- Boleh sebagian (split bill); order tetap `open` sampai `paid_total ≥ grand_total`. Juga untuk pesanan meja
  `awaiting_payment` (ADR 0014, lihat [`self-order.md`](self-order.md)); kedaluwarsa → `409 ORDER_EXPIRED`.
- Saat lunas: `status: completed`, stok dipotong, dan **order pindah ke shift yang menerima uang**
  (Q20) agar rekap kas laci benar walau open bill dibuat di shift sebelumnya.
- Order dikunci saat menambah pembayaran: pembayaran bersamaan tidak bisa melebihi sisa
  (diuji 6 pembayaran paralel → hanya yang muat yang diterima).
- Semua `payments[].id` sudah tercatat di order ini → `200` replay.
- `payments: []` melunasi open bill yang sisa tagihannya 0 (diskon 100%); bila masih ada sisa → `422`.
  Kiriman ulang setelah selesai → `200` replay.

## `GET /v1/orders`

Query: `date` (YYYY-MM-DD, **tanggal lokal outlet**), `status` (`open`/`awaiting_payment`/`completed`/`voided`/`expired`),
`channel` (`pos`/`self_order`),
`shift_id`, `search` (nomor order / meja), `page`, `per_page`. Urut terbaru.

Visibilitas (Q21):

| Pengguna | Melihat |
|---|---|
| `order.view_all` + `report.view` (owner, manager) | Semua order |
| `order.view_all` tanpa `report.view` (supervisor) | Semua order **hari ini** |
| `order.view_own` (kasir) | Order di shift yang ia buka + shift yang **sedang terbuka di device-nya** (ganti kasir tanpa hitung, ADR 0017) + **semua open bill** |

Order di luar visibilitas → `403` di detail/struk; order tenant lain → `404`.

## `GET /v1/orders/{id}/receipt`

Data struk siap cetak: `outlet` (nama, alamat, header, footer), `order_number`, `order_type_label`,
`table_label`, `member_name` & `member_tier` (atau `null`), `cashier_name`, `created_at` (UTC) + `created_at_local` (`08/10/2026 12.00`, zona outlet),
`lines` (nama, opsi, qty, harga, diskon, total, catatan), `totals` (dengan **tarif pajak/service saat
transaksi**), `payments`, `is_void`, `void_reason`.

`outlet.show_rounding` (SPEC Q48): `false` (bawaan) → **jangan cetak baris pembulatan**; `totals.grand_total`
sudah termasuk pembulatan. `true` → cetak baris "Pembulatan" dari `totals.rounding` bila tidak nol.
Struk web (`/dashboard/orders/{id}/receipt`) mengikuti aturan yang sama.

## `POST /v1/orders/{id}/void`

Body `{ "reason": "…", "approver_user_id"?: uuid, "approver_pin"?: "123456" }`.

- Hanya order di **shift yang masih terbuka** (`409 SHIFT_NOT_OPEN`); alasan wajib.
- Owner/manager/supervisor langsung; kasir wajib PIN approver (`403 APPROVAL_REQUIRED` tanpa PIN,
  `SELF_APPROVAL_NOT_ALLOWED`, `INVALID_PIN` / `PIN_LOCKED` ikut penguncian).
- Order selesai: stok dikembalikan (`stock_movements` tipe `void_return`). Open bill: tanpa stok.
- Pembayaran ikut `voided`; order void tidak dihitung di ringkasan shift.
- Approver void dicatat di `void_approved_by` (terpisah dari `approved_by` diskon).
- Void kedua kali → `200`, data sama, `meta.idempotent_replay: true`.

Test: `OpenBillTest.php`, `OrderQueryTest.php`, `VoidOrderTest.php` di `tests/Feature/Api/V1/Order/`.

## `POST /v1/orders/{id}/refunds` — retur sebagian (ADR 0018)

| Field | Wajib | Aturan |
|---|---|---|
| `id` | Ya | UUID dari aplikasi (idempotency key) |
| `items[]` | Ya | 1–100 baris `{ order_item_id, qty }`; qty ≤ terjual − sudah diretur (`refunded_qty`) |
| `reason` | Ya | maks. 255 |
| `method` | Ya | `cash` (kas keluar laci shift berjalan device, alasan Retur) / `manual` (diproses di luar sistem) |
| `restock` | tidak | bawaan `true`: produk lacak stok kembali ke stok outlet |
| `approver_user_id`, `approver_pin` | 🔑 | kasir tanpa `order.refund` |

- Order wajib **selesai** di outlet device, maks. `refund_max_days` hari setelan outlet (bawaan 30; 0 = retur ditolak `VALIDATION_ERROR`); shift wajib terbuka di device
  (`409 SHIFT_NOT_OPEN`). Qty berlebih / order bukan selesai / sudah habis diretur / lewat batas → `422 VALIDATION_ERROR`.
- Nilai dihitung server: bagian proporsional `grand_total`; retur yang menghabiskan semua item = sisa nilai order.
  Bagian yang dibayar poin kembali sebagai poin (`points_returned`), poin hasil belanja ditarik (`points_reversed`);
  `payout_amount` = uang yang dikembalikan. Tunai melebihi isi laci → `422` (pilih `manual`).
- Order tetap `completed`; `refunded_total` & `items[].refunded_qty` bertambah, `refunds[]` di detail order.
  Order yang sudah diretur tidak bisa di-void.

Respons `201`: `{ id, order_id, shift_id, reason, method, restock, amount, payout_amount, points_returned, points_reversed,
user_id, user_name, approved_by, approved_by_name, items: [{ order_item_id, product_name, qty, amount }], created_at }`.

## `GET /v1/refundable-orders/{nomor}`

Cari transaksi untuk diretur: nomor order di outlet device, status selesai, dalam batas hari; selain itu `404 NOT_FOUND`.
Sengaja tidak mengikuti visibilitas Q21 (kasir perlu meretur transaksi shift lain); retur tetap butuh izin/PIN.

Test: `tests/Feature/Api/V1/Order/RefundOrderTest.php`, `tests/Feature/Cashier/WebCashierRefundTest.php`

## `POST /v1/orders/{id}/split` · `POST /v1/orders/{id}/merge` — pecah & gabung open bill (ADR 0019)

Syarat kedua endpoint: open bill (`status: open`) di outlet device, **belum ada pembayaran**, tanpa diskon item/order,
voucher, atau kartu stempel (lepas dulu lewat `PUT /orders/{id}`); selain itu `422 VALIDATION_ERROR`. Harga dihitung
ulang dengan harga & setelan saat ini.

**Pecah** — body `{ new_order_id, items: [{ order_item_id, qty }], table_label? }`. Item & qty pindah ke open bill baru
(`new_order_id` = idempotency key), bill asal minimal tersisa 1 item, member tetap di bill asal. Respons `201`
`{ source: Order, target: Order }`; kiriman ulang → `200` + `meta.idempotent_replay: true`.

**Gabung** — body `{ source_order_id }`. Semua item sumber pindah ke bill `{id}`; sumber menjadi `status: merged`
(`merged_into_id` = tujuan) dan tidak masuk laporan. Dua member berbeda → `422`. Respons = bill tujuan.

Pindah meja: `PUT /orders/{id}` dengan `table_label` baru.

Test: `tests/Feature/Api/V1/Order/SplitMergeOpenBillTest.php`, `tests/Feature/Cashier/WebCashierSplitMergeTest.php`

## `POST /v1/orders/quote` — pratinjau total (ADR 0022)

Body sama dengan checkout tanpa `id`, `payments`, approver: `items[]`, `discount`, `member_id`, `voucher_code`/`voucher_id`,
`stamp_program_ids`. Tidak menyimpan apa pun. Respons: `subtotal`, `promo_discount`, `promotions[]` `{promotion_id, name,
amount}`, `stamp_free_total`, `order_discount`, `voucher_discount`, `member_discount`, `discount_total`, `service_total`,
`tax_total`, `rounding`, `grand_total`, `lines[]`. Pakai untuk menampilkan promo otomatis di keranjang aplikasi;
nominal final tetap dihitung ulang saat checkout/open bill (promo yang berlaku saat itu).

Order & struk memuat `promo_discount` dan `promotions` (bagian dari `discount_total`).
