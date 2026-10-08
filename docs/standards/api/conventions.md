# Konvensi API

## 1. URL & method

| Aturan | Contoh benar | Contoh salah |
|---|---|---|
| Prefix versi | `/api/v1/orders` | `/api/orders` |
| Resource jamak, kebab-case | `/option-groups`, `/payment-methods` | `/optionGroup`, `/payment_method` |
| ID di path | `/orders/{id}` | `/orders?id=...` |
| Aksi non-CRUD sebagai sub-resource kata kerja | `POST /orders/{id}/void`, `POST /shifts/{id}/close` | `POST /voidOrder` |
| Maksimal 2 level nesting | `/orders/{id}/payments` | `/shifts/{id}/orders/{id}/payments` |

| Method | Pemakaian | Status sukses |
|---|---|---|
| `GET` | Baca, tanpa efek samping | 200 |
| `POST` | Buat data / aksi | 201 (data baru), 200 (aksi atau replay idempotent) |
| `PUT` | Ganti/simpan penuh (termasuk upsert open bill) | 200 |
| `PATCH` | Ubah sebagian kecil (mis. availability) | 200 |
| `DELETE` | Hapus (soft delete untuk master) | 200 dengan `data: null` |

## 2. Header

| Header | Arah | Wajib | Keterangan |
|---|---|---|---|
| `Authorization: Bearer {token}` | Request | Semua kecuali publik | Token Sanctum |
| `X-App-Version: 1.4.0` | Request | Semua | Dicek `CheckAppVersion`; di bawah `min_version` → `426 APP_UPDATE_REQUIRED` |
| `Accept: application/json` | Request | Semua | Memastikan error selalu JSON |
| `X-Request-Id` | Request & Response | Opsional di request | Jika tidak dikirim, server membuat UUID; selalu dikembalikan |
| `If-None-Match` / `ETag` | Keduanya | Hanya `/catalog` | Respons `304` tanpa body jika tidak berubah |

## 3. Penamaan field

- `snake_case` untuk semua key JSON dan query string.
- Foreign key: `{tunggal}_id` (`product_id`, `payment_method_id`).
- Boolean diawali `is_`/`has_`/kata kerja sesuai kolom DB (`is_active`, `track_stock`).
- Relasi yang disertakan memakai nama relasi (`items`, `payments`, `shift`), bukan `_data`.
- Field tidak pernah dihapus dari respons karena kosong: kirim `null`.

## 4. Tipe data

| Jenis | Format JSON | Contoh |
|---|---|---|
| ID | String UUID | `"0192f3a1-7c4e-7b21-9a55-3f1e2d4c5b6a"` |
| Uang (respons) | String desimal 2 digit | `"62000.00"` |
| Uang (request) | Number atau string desimal; server menormalkan | `50000`, `"50000.00"` |
| Persentase | String desimal 2 digit | `"11.00"` |
| Kuantitas | Number integer | `2` |
| Tanggal-waktu | ISO 8601 UTC dengan `Z` | `"2026-10-08T07:02:11Z"` |
| Tanggal saja | `YYYY-MM-DD` (tanggal lokal outlet) | `"2026-10-08"` |
| Enum | String huruf kecil snake_case | `"completed"`, `"dine_in"` |
| Kosong | `null` | `"table_label": null` |
| Daftar kosong | `[]` | `"payments": []` |

Flutter memakai package `decimal` untuk uang, **tidak pernah** `double`.

## 5. Pagination

- Query: `page` (default 1), `per_page` (default 20, maks. 100; nilai lebih besar dipotong ke 100).
- Respons: `meta.pagination` — lihat [response-envelope.md](response-envelope.md).
- Endpoint yang mengembalikan daftar kecil dan tetap (mis. `/catalog`, `/payment-methods`) tidak dipaginasi.

## 6. Filter, pencarian, urutan

| Kebutuhan | Pola | Contoh |
|---|---|---|
| Filter nilai tunggal | `{field}=` | `?status=completed&shift_id=...` |
| Rentang tanggal | `from=` & `to=` (inklusif, `YYYY-MM-DD`) | `?from=2026-10-01&to=2026-10-08` |
| Tanggal tunggal | `date=` | `?date=2026-10-08` |
| Pencarian teks | `search=` | `?search=latte` |
| Urutan | `sort=` dengan `-` untuk menurun | `?sort=-created_at` |

Filter yang tidak dikenal diabaikan. Nilai filter yang tidak valid → `422 VALIDATION_ERROR`.

## 7. Versi & kompatibilitas

| Perubahan | Merusak? | Tindakan |
|---|---|---|
| Menambah field di respons | Tidak | Langsung |
| Menambah field opsional di request | Tidak | Langsung |
| Menambah endpoint | Tidak | Langsung |
| Menambah nilai enum | **Ya** bagi app lama yang `switch` ketat | Koordinasi dengan tim Flutter; app wajib punya fallback |
| Mengganti nama/menghapus field, mengubah tipe | Ya | Naikkan `min_version` di `/admin` **atau** buat `/api/v2` |
| Field request opsional → wajib | Ya | Sama seperti di atas |

## 8. Rate limit

| Grup | Batas | Kunci |
|---|---|---|
| `auth/login`, `auth/pin-login` | 5/menit | IP + device_uid |
| API umum | 120/menit | Token |

Melewati batas → `429 TOO_MANY_REQUESTS` dengan header `Retry-After`.

## 9. Bahasa

`message` selalu Bahasa Indonesia, kalimat singkat, siap ditampilkan ke kasir, tanpa titik di akhir
(`"Shift belum dibuka"`). Tidak berisi detail teknis.
