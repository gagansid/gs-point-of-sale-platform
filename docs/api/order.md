# API — Order & pembayaran

Token user terikat device kasir + shift terbuka di device itu. Open bill, tambah pembayaran,
riwayat, struk, dan void: Minggu 5.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| POST | `/checkout` | `order.create` | `id` order & `payments[].id` | `CheckoutOrder` |

## `POST /api/v1/checkout`

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
| `payments` | 1–10; `id` UUID unik; `amount` > 0; `tendered` untuk tunai; `reference` wajib bila metode `requires_reference` |
| `approver_user_id` + `approver_pin` | hanya bila diskon di atas batas role (PIN 6 digit) |

### Aturan

1. **Perhitungan** (`OrderCalculator`, urutan SPEC): harga item → subtotal → diskon order → service →
   pajak (exclusive/inclusive) → pembulatan ke kelipatan Rp `outlet.rounding` (half-up).
2. **Item:** produk harus aktif & tersedia; opsi harus milik grup opsi produk; jumlah pilihan per grup
   sesuai `min_select`–`max_select`. Pelanggaran → `422` per field (`items.N.product_id`, `items.N.option_ids`).
3. **Pembayaran:** non-tunai dialokasikan dulu dan tidak boleh melebihi sisa (`PAYMENT_EXCEEDS_BALANCE`,
   `error.details.remaining`); tunai menutup sisa, kelebihannya `change`. Total kurang → `422`
   (`error.details.remaining`).
4. **Diskon:** persen total diskon terhadap harga kotor dibandingkan `outlet.discount_limits[role]`.
   Di atas batas tanpa `order.discount_over_limit` → `422 DISCOUNT_OVER_LIMIT`
   (`details.limit_percent`, `details.discount_percent`), kecuali disertai PIN approver yang berwenang
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
| 422 | `VALIDATION_ERROR` | Bentuk data, item/opsi tidak valid, reference kosong, pembayaran kurang, `payments[].id` sudah dipakai |
| 422 | `PAYMENT_EXCEEDS_BALANCE` | Non-tunai melebihi sisa |
| 422 | `DISCOUNT_OVER_LIMIT` | Diskon di atas batas tanpa approval |
| 423 | `PIN_LOCKED` | PIN approver terkunci |

### Diuji

- `tests/Unit/OrderCalculatorTest.php`: semua langkah, inclusive/exclusive, pembulatan.
- `tests/Feature/Api/V1/Order/CheckoutTest.php`: termasuk idempotensi, isolasi tenant, approval.
- **Uji konkurensi manual (MySQL, 8 worker PHP):**
  - 30 checkout paralel menghasilkan 30 nomor unik berurutan tanpa celah, dan stok tepat berkurang 30;
  - 10 request bersamaan dengan `id` sama menghasilkan 1 order (`201` sekali, `200` replay 9 kali).
