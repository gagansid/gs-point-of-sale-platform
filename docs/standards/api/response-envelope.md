# Envelope Respons

Semua endpoint `/api/*` — termasuk error dari framework — mengembalikan envelope ini.
Dibuat **hanya** lewat `App\Support\ApiResponse`; dilarang `response()->json()` langsung di controller.

## 1. Sukses — objek tunggal

```json
{
  "success": true,
  "message": "Transaksi berhasil disimpan",
  "data": {
    "id": "0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a",
    "order_number": "JKT01-261008-0042",
    "status": "completed",
    "grand_total": "62000.00",
    "created_at": "2026-10-08T07:02:11Z"
  },
  "meta": {
    "request_id": "9f1c2d3e-4b5a-6c7d-8e9f-0a1b2c3d4e5f",
    "idempotent_replay": false
  }
}
```

## 2. Sukses — daftar dengan pagination

```json
{
  "success": true,
  "message": "OK",
  "data": [{ "id": "..." }, { "id": "..." }],
  "meta": {
    "request_id": "...",
    "pagination": { "current_page": 1, "per_page": 20, "total": 135, "last_page": 7 }
  }
}
```

## 3. Gagal

```json
{
  "success": false,
  "message": "Data tidak valid",
  "error": {
    "code": "VALIDATION_ERROR",
    "details": { "items.0.qty": ["Qty minimal 1"] }
  },
  "meta": { "request_id": "..." }
}
```

## 4. Aturan field

| Field | Aturan |
|---|---|
| `success` | `true` untuk HTTP 2xx, `false` selainnya |
| `message` | Selalu ada. Bahasa Indonesia. Sukses tanpa pesan khusus: `"OK"` |
| `data` | Ada hanya saat sukses. Objek, array, atau `null` (mis. setelah `DELETE`) |
| `error.code` | Ada hanya saat gagal. Dari [error-codes.md](error-codes.md) |
| `error.details` | `null`, atau objek. Untuk `VALIDATION_ERROR`: `{ "field.path": ["pesan"] }`. Untuk error bisnis: data pendukung, mis. `{ "remaining": "12000.00" }` |
| `meta.request_id` | Selalu ada |
| `meta.idempotent_replay` | Ada di endpoint idempotent (checkout, open bill, payment) |
| `meta.pagination` | Ada di endpoint daftar berpaginasi |

## 5. Pesan sukses baku

Pakai pesan berikut agar konsisten antar endpoint:

| Situasi | `message` |
|---|---|
| Baca data | `OK` |
| Buat data | `{Objek} berhasil ditambahkan` |
| Ubah data | `{Objek} berhasil diperbarui` |
| Hapus data | `{Objek} berhasil dihapus` |
| Checkout / payment | `Transaksi berhasil disimpan` |
| Replay idempotent | Sama dengan pesan saat pertama kali |
| Void | `Transaksi berhasil dibatalkan` |
| Buka / tutup shift | `Shift berhasil dibuka` / `Shift berhasil ditutup` |

## 6. Penggunaan di kode

```php
// Objek tunggal
return ApiResponse::success(OrderResource::make($order)->resolve(), 'Transaksi berhasil disimpan', 201, [
    'idempotent_replay' => false,
]);

// Daftar berpaginasi
return ApiResponse::paginated($orders, OrderResource::class);

// Error (biasanya tidak dipanggil langsung — lempar BusinessException)
return ApiResponse::error('NOT_FOUND', 'Data tidak ditemukan', 404);
```

Implementasi `ApiResponse` dan pemetaan exception: lihat `docs/SPEC.md` → *Standar respons* dan
[implementation.md](implementation.md).
