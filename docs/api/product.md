# API — Katalog & produk

Standar: [`docs/standards/api/`](../standards/api/README.md). Skema lengkap: OpenAPI `/docs/api`.
Semua endpoint memakai **token user** + header `X-App-Version`.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/catalog` | semua | baca, ETag | `GetCatalog` |
| GET | `/products` | semua | baca | — |
| GET | `/products/barcode/{code}` | semua | baca | — |
| POST | `/products` | `product.manage` | `id` opsional | `SaveProduct` |
| PUT | `/products/{id}` | `product.manage` | ya | `SaveProduct` |
| DELETE | `/products/{id}` | `product.manage` | ya (kedua kali 404) | `DeleteProduct` |
| POST | `/products/{id}/stock` | `stock.adjust` | **`id` wajib** | `AdjustStock` |
| PATCH | `/products/{id}/availability` | `product.toggle_available` | ya | `SetProductAvailability` |
| POST | `/categories` | `product.manage` | `id` opsional | `SaveCategory` |
| PUT, DELETE | `/categories/{id}` | `product.manage` | ya | `SaveCategory`, `DeleteCategory` |
| POST | `/option-groups` | `product.manage` | `id` opsional | `SaveOptionGroup` |
| PUT, DELETE | `/option-groups/{id}` | `product.manage` | ya | `SaveOptionGroup`, `DeleteOptionGroup` |

Error umum semua endpoint: `401 UNAUTHENTICATED`, `403 FORBIDDEN` (permission),
`403 TENANT_SUSPENDED`, `404 NOT_FOUND` (termasuk data tenant lain), `422 VALIDATION_ERROR`,
`426 APP_UPDATE_REQUIRED`.

---

## `GET /v1/catalog`

Seluruh katalog untuk aplikasi kasir dalam satu respons.

- Produk **nonaktif tidak dikirim**, begitu juga kategori/grup opsi nonaktif dan produk di kategori nonaktif. Produk "habis" tetap dikirim dengan `is_available: false`
  (tampilkan abu-abu, tidak bisa dipilih).
- `cost_price` hanya dikirim untuk user dengan `product.manage`.
- `is_favorite: true` → tampilkan di tab **Favorit** layar kasir (ditandai owner/manager di dashboard).
- Urutan tampil produk = `sort_order` (diatur seret-lepas di dashboard), lalu `name`.
- `min_stock` & `is_low_stock` hanya bermakna bila `track_stock: true` (selain itu `null` / `false`).
  `is_low_stock` = stok > 0 dan ≤ `min_stock`; stok ≤ 0 tetap dianggap habis.
- **ETag:** simpan header `ETag`, kirim kembali sebagai `If-None-Match`. Jika katalog tidak
  berubah → `304` tanpa body (pakai cache lokal).

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "categories": [{ "id": "…", "name": "Kopi", "sort_order": 1 }],
    "products": [{
      "id": "…", "category_id": "…", "name": "Es Kopi Susu", "sku": "KOP-01", "barcode": null,
      "price": "22000.00", "track_stock": false, "stock_qty": null, "min_stock": null, "is_low_stock": false,
      "is_active": true, "is_available": true, "is_favorite": true, "image_url": null,
      "option_group_ids": ["…ukuran", "…gula"], "updated_at": "2026-10-08T07:02:11Z"
    }],
    "option_groups": [{
      "id": "…", "name": "Ukuran", "min_select": 1, "max_select": 1,
      "options": [{ "id": "…", "name": "Large", "price_delta": "5000.00", "sort_order": 1 }]
    }],
    "payment_methods": [{ "id": "…", "name": "Debit", "category": "debit", "requires_reference": true, "is_active": true }]
  },
  "meta": { "request_id": "…" }
}
```

`option_group_ids` berurutan sesuai urutan tampil. `min_select: 1` = wajib pilih (mis. Ukuran).

Test: `tests/Feature/Api/V1/Product/CatalogTest.php`

---

## `GET /v1/products`

Query: `search` (nama/SKU/barcode; `%` dan `_` dicari apa adanya), `category_id`, `is_active`,
`sort`, `page`, `per_page` (default 20, maks. 100). Respons daftar + `meta.pagination`.

`sort` = nama kolom untuk urutan naik, awalan `-` untuk turun (`sort=price`, `sort=-price`).
Kolom yang diizinkan: `name`, `price`, `stock_qty`, `sort_order`, `created_at`, `updated_at`;
nilai lain → `422 VALIDATION_ERROR`. Tanpa `sort`: `sort_order` lalu `name`. Seri dipecah dengan `id`
agar urutan antar halaman stabil.

## `GET /v1/products/barcode/{code}`

Produk **aktif** dengan barcode tersebut. `code`: `[A-Za-z0-9-]`, maks. 50. Tidak ada → 404.

---

## `POST /v1/products` · `PUT /v1/products/{id}`

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `id` | uuid | Tidak | Hanya POST; idempotency key |
| `category_id` | uuid | Tidak | Kategori tenant sendiri; `null` = tanpa kategori |
| `name` | string | Ya | maks. 150 |
| `sku` | string | Tidak | maks. 50, unik per tenant |
| `barcode` | string | Tidak | `[A-Za-z0-9-]`, maks. 50, unik per tenant |
| `price` | uang | Ya | ≥ 0, maks. 2 desimal (`22000` atau `"22000.00"`) |
| `cost_price` | uang | Tidak | ≥ 0 |
| `track_stock` | bool | Tidak | default `false` |
| `stock_qty` | int | Tidak | **Hanya POST** = stok awal; di PUT → 422 |
| `min_stock` | int\|null | Tidak | 0–1.000.000; batas peringatan "stok menipis"; `null` = tanpa peringatan |
| `is_active` | bool | Tidak | default `true` |
| `is_favorite` | bool | Tidak | default `false`; tampil di tab Favorit kasir |
| `option_group_ids` | uuid[] | Tidak | maks. 20, grup tenant sendiri, urutan = urutan tampil |

PUT: field yang tidak dikirim tetap memakai nilai lama. Respons `201` (baru), `200` (replay/ubah)
dengan `meta.idempotent_replay`.

Test: `tests/Feature/Api/V1/Product/ProductTest.php`

---

## `POST /v1/products/{id}/stock`

Penyesuaian stok manual; tercatat di riwayat `stock_movements`.

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `id` | uuid | **Ya** | Idempotency key: request ganda tidak mengubah stok dua kali |
| `qty_change` | int | Ya | ≠ 0; positif = tambah, negatif = kurang |
| `reason` | string | Ya | maks. 255 |

```json
{
  "success": true,
  "message": "Stok berhasil disesuaikan",
  "data": {
    "movement": { "id": "…", "product_id": "…", "type": "adjustment", "qty_change": -3, "qty_after": 7, "reason": "Rusak", "created_at": "…Z" },
    "product": { "id": "…", "stock_qty": 7, "…": "…" }
  },
  "meta": { "request_id": "…", "idempotent_replay": false }
}
```

- Stok boleh minus (SPEC). Baris produk dikunci saat menyesuaikan.
- Produk tanpa `track_stock` → `422 VALIDATION_ERROR` ("Produk ini tidak melacak stok").

## `PATCH /v1/products/{id}/availability`

Body `{ "is_available": false }`. Menandai menu habis/tersedia tanpa mengubah data lain.
Owner, manager, supervisor.

Test keduanya: `tests/Feature/Api/V1/Product/StockAndAvailabilityTest.php`

---

## Kategori — `POST /categories`, `PUT/DELETE /categories/{id}`

Body `{ "id"?: uuid, "name": string ≤ 100, "sort_order"?: int, "is_active"?: bool }`. Kategori baru tanpa
`sort_order` diletakkan paling akhir. `is_active` default `true`; di PUT bila tidak dikirim tetap memakai
nilai lama. **Kategori nonaktif tidak dikirim di katalog beserta produknya** dan produknya ditolak saat
checkout (SPEC Q27). **Hapus kategori tidak menghapus produknya** — produk menjadi
tanpa kategori.

Test: `tests/Feature/Api/V1/Product/CategoryTest.php`

## Grup opsi — `POST /option-groups`, `PUT/DELETE /option-groups/{id}`

```json
{
  "id": "…opsional",
  "name": "Ukuran",
  "min_select": 1,
  "max_select": 1,
  "is_active": true,
  "options": [
    { "id": "…opsi lama", "name": "Regular", "price_delta": 0 },
    { "name": "Large", "price_delta": 5000 }
  ]
}
```

- `0 ≤ min_select ≤ max_select ≤ 20`, `max_select ≥ 1`, 1–50 opsi, nama opsi unik dalam grup.
- PUT menyinkronkan opsi: ber-`id` diubah, tanpa `id` ditambah, yang tidak dikirim dihapus.
  `id` opsi milik grup lain diperlakukan sebagai opsi baru (tidak bisa diambil alih).
- `is_active` default `true`; di PUT bila tidak dikirim tetap memakai nilai lama. Grup nonaktif tidak
  dikirim di katalog dan dilepas dari `option_group_ids` produk; saat checkout tidak wajib dipilih dan
  opsinya ditolak (SPEC Q27).
- Hapus grup → dilepas dari semua produk.

Test: `tests/Feature/Api/V1/Product/OptionGroupTest.php`
