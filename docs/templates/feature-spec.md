# {Nama fitur}

> Status: Draft | Disetujui | Selesai · Issue: #{nomor} · Domain: {order|shift|...}
> Rujukan SPEC: `docs/SPEC.md#{anchor}`

## 1. Tujuan

Satu paragraf: masalah apa yang diselesaikan dan untuk siapa.

## 2. Cakupan

| Termasuk | Tidak termasuk |
|---|---|
| ... | ... |

## 3. Alur pengguna

1. Kasir ...
2. Server ...
3. ...

## 4. Aturan bisnis

| # | Aturan | Error code jika dilanggar |
|---|---|---|
| R1 | ... | `...` |

## 5. Permission

| Aksi | Permission | Jalur PIN (🔑)? |
|---|---|---|
| ... | `order.void` | Ya, untuk kasir |

## 6. Perubahan data

| Tabel | Perubahan | Migration |
|---|---|---|
| `orders` | Kolom baru `...` | `add_..._to_orders_table` |

## 7. Komponen kode

| Lapisan | File |
|---|---|
| Action | `app/Actions/{Domain}/{Name}.php` |
| Service | ... |
| Endpoint | `POST /api/v1/...` → `docs/api/{domain}.md` |
| Filament | `app/Filament/Dashboard/Resources/...` |

## 8. UI (jika ada)

Halaman/aksi yang ditambahkan, komponen standar yang dipakai (rujuk `docs/standards/ui/components/`),
sketsa ASCII bila perlu.

## 9. Rencana test

| # | Kasus | Jenis |
|---|---|---|
| T1 | Sukses ... | API |
| T2 | Validasi ... | API |
| T3 | Permission ... | API |
| T4 | Isolasi tenant ... | API |
| T5 | Request ganda ... | API |

## 10. Pertanyaan terbuka

- [ ] ...
