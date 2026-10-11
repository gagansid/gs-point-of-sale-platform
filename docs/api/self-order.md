# API — Self-order (pesanan dari QR meja)

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user terikat device kasir** + header `X-App-Version`. Base URL: [README](README.md).
Keputusan: [ADR 0014](../adr/0014-table-qr-self-order-and-midtrans.md), SPEC Q68–Q73, Q76–Q78.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/v1/self-orders` | `self_order.handle` | baca | — |
| POST | `/v1/self-orders/{id}/accept` | `self_order.handle` + shift terbuka | ditangani sekali (`409`) | `AcceptSelfOrder` |
| POST | `/v1/self-orders/{id}/reject` | `self_order.handle` | ditangani sekali (`409`) | `RejectSelfOrder` |
| GET | `/v1/orders?status=awaiting_payment&channel=self_order` | `order.view_own` / `order.view_all` | baca | `VisibleOrders` |
| POST | `/v1/orders/{id}/payments` | `order.create` | `payments[].id` | `AddPayment` |

Error umum: `401 UNAUTHENTICATED`, `403 FORBIDDEN`, `403 DEVICE_NOT_REGISTERED`, `404 NOT_FOUND` (pesanan bisnis/outlet
lain), `409 SHIFT_NOT_OPEN`, `409 ORDER_NOT_PENDING`, `409 ORDER_EXPIRED`, `422 VALIDATION_ERROR` (item/opsi tidak
tersedia saat diterima), `422 VOUCHER_INVALID`.

## Alur

1. Pelanggan memindai QR meja (`gspos.id/order/t/{token}`, web), memilih menu, mengirim pesanan → `pending`.
2. Aplikasi kasir **polling 5–10 detik** `GET /self-orders` (menu **Pesanan masuk**, badge = `meta.pending_count`).
3. Kasir **terima** → order dibuat di shift perangkat dengan status `awaiting_payment`, `channel: self_order`, nomor
   order, `table_label` = nama meja, harga & member/voucher dihitung ulang (stok belum dipotong). Atau **tolak** dengan
   alasan (tampil di HP).
4. Pelanggan membayar online di HP (Midtrans, otomatis lunas lewat webhook) **atau** di kasir: menu **Belum dibayar**
   (`GET /orders?status=awaiting_payment&channel=self_order`, jumlah = `meta.unpaid_count`) → `POST /orders/{id}/payments`
   seperti melunasi open bill (item tidak bisa diubah; `PUT /orders/{id}` → `409 ORDER_ALREADY_CLOSED`).
5. Tidak ditangani / tidak dibayar dalam `payment_timeout_minutes` (bawaan 15) → kedaluwarsa (`ORDER_EXPIRED`).
   Batalkan pesanan yang sudah diterima = void (PIN, Q18).

Metode bayar `category: online` **tidak** dikirim di `/catalog` dan ditolak bila dikirim kasir (hanya dari Midtrans).

## `GET /v1/self-orders`

Pesanan `pending` outlet perangkat, paling lama di atas (maks. 50). Nama produk/opsi dari katalog saat ini;
`estimated_total` hanya perkiraan.

```json
{
  "success": true,
  "message": "Pesanan masuk",
  "data": [
    {
      "id": "…", "status": "pending", "table": { "id": "…", "name": "Meja 5" }, "customer_name": "Andi",
      "member": null,
      "items": [{ "product_id": "…", "product_name": "Es Kopi Susu", "qty": 1, "option_ids": ["…"], "option_names": ["Large"], "notes": "less ice" }],
      "notes": "pedas", "voucher_code": null, "estimated_total": "31500.00", "reject_reason": null, "order_id": null,
      "created_at": "2026-10-11T13:00:00Z", "expires_at": "2026-10-11T13:15:00Z"
    }
  ],
  "meta": { "pending_count": 1, "unpaid_count": 0, "request_id": "…" }
}
```

## `POST /v1/self-orders/{id}/accept`

Tanpa body. `201` + order (`OrderResource`, `status: awaiting_payment`, `channel: self_order`, `accepted_at`).
Total 0 (voucher menutup semua) → langsung `completed`.

## `POST /v1/self-orders/{id}/reject`

Body `{ "reason": "Croissant habis" }` (wajib, maks. 255). `200` → `{ id, status: "rejected", reject_reason }`.

## Field order baru (semua endpoint order)

`channel` (`pos`/`self_order`), `dining_table_id`, `accepted_at`, `refund_required` (dibayar online lalu di-void →
owner mengembalikan dana manual lewat Midtrans). Status order baru: `awaiting_payment`, `expired`.
`GET /orders` menerima filter `channel`.

## Webhook Midtrans (bukan untuk aplikasi)

`POST /webhooks/midtrans/{tenant_id}` di domain utama — URL yang owner pasang di dashboard Midtrans miliknya
(Pengaturan → Pembayaran online menampilkan URL-nya). Tanda tangan SHA512 diverifikasi, status dicek ulang ke API
Midtrans, nominal dicocokkan; idempotent. `503` = Midtrans tidak bisa dihubungi, Midtrans mengirim ulang.
