# Idempotency

Jaringan kasir tidak stabil: request yang sama bisa terkirim dua kali. Server harus menjamin
**satu aksi = satu data**, berapa pun jumlah request.

## 1. Endpoint idempotent

| Endpoint | Kunci idempotency |
|---|---|
| `POST /checkout` | `id` order (dari Flutter) + `payments[].id` |
| `PUT /orders/{id}` | `{id}` order (upsert open bill) |
| `POST /orders/{id}/payments` | `id` payment (dari Flutter) |
| `POST /orders/{id}/void` | Status order (void kedua → kembalikan order yang sudah void) |
| `POST /shifts/{id}/close` | Status shift (close kedua → kembalikan ringkasan yang sama) |

ID dibuat Flutter dengan **UUID v7** (berurutan waktu).

## 2. Perilaku

| Situasi | Respons |
|---|---|
| ID baru | Proses normal, `201`, `meta.idempotent_replay: false` |
| ID sudah ada, milik tenant yang sama | `200`, data tersimpan (bukan data request), `meta.idempotent_replay: true` |
| ID sudah ada, milik tenant lain | `404 NOT_FOUND` (jangan bocorkan keberadaan) |
| Dua request bersamaan dengan ID sama | Satu diproses, yang lain menunggu lock lalu menjadi replay |

Replay **tidak** memvalidasi ulang isi request terhadap data lama. Data lama selalu menang.

## 3. Pola implementasi di Action

```php
public function handle(User $actor, CheckoutData $data): CheckoutResult
{
    // 1. Cek cepat di luar transaksi: replay paling sering terjadi
    if ($existing = Order::query()->find($data->id)) {
        return CheckoutResult::replay($existing);
    }

    try {
        return DB::transaction(fn () => CheckoutResult::created($this->create($actor, $data)));
    } catch (UniqueConstraintViolationException) {
        // 2. Request kembar lolos cek di atas bersamaan; primary key menjadi penjaga terakhir
        return CheckoutResult::replay(Order::query()->findOrFail($data->id));
    }
}
```

Controller meneruskan `$result->isReplay` ke `meta.idempotent_replay` dan memilih status 200/201.

## 4. Penguncian (lock)

| Data | Cara | Alasan |
|---|---|---|
| Nomor order | `order_sequences` + `lockForUpdate()` | Dua kasir tidak mendapat nomor sama |
| Stok | Update atomik `stock_qty = stock_qty - ?` | Hindari lost update |
| Sisa tagihan saat tambah payment | `lockForUpdate()` pada order | Cegah overpayment non-tunai paralel |
| Void | `lockForUpdate()` pada order | Stok tidak dikembalikan dua kali |

## 5. Test wajib

Setiap endpoint di §1 punya test "request ganda" yang memastikan:

- jumlah baris di tabel tetap 1 (order, payment, item),
- stok hanya berkurang sekali,
- respons kedua `200` dengan `meta.idempotent_replay: true` dan data identik.
