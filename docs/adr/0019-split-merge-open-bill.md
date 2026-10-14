# ADR 0019: Pecah & gabung open bill

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q95–Q96, Q20 (open bill lintas shift), ADR 0013 (member pricing)

## Konteks

Satu meja = satu open bill. Rombongan yang ingin bayar sendiri-sendiri hanya bisa memakai split payment (membagi
metode bayar, bukan item), dan dua meja yang digabung harus dibayar terpisah. Pindah meja sudah bisa lewat ubah open
bill (`table_label` di `PUT /orders/{id}`).

## Keputusan

| Topik | Keputusan |
|---|---|
| Pecah | Item & qty dipindahkan dari open bill ke open bill **baru** (`new_order_id` dari klien = idempotency key, nomor order baru, shift perangkat pelaku); bill asal minimal tersisa 1 item |
| Gabung | Semua item bill sumber ditambahkan ke bill tujuan; bill sumber berstatus baru **`merged`** + `merged_into_id` (tidak masuk laporan, nomor tetap tercatat) |
| Syarat | Keduanya open bill (`open`) di outlet perangkat, **belum ada pembayaran**, tanpa diskon item/order, voucher, atau kartu stempel (harga dihitung ulang; approval diskon tidak bisa dipakai ulang tanpa PIN). Lepas dulu bila ada → `VALIDATION_ERROR` |
| Member | Pecah: member tetap di bill asal, bill baru tanpa member. Gabung: member tujuan, atau member sumber bila tujuan tanpa member; dua member berbeda → `VALIDATION_ERROR` |
| Harga | Kedua bill dihitung ulang dengan harga & setelan saat ini lewat jalur yang sama dengan ubah open bill (`SaveOpenBill`) |
| Izin | `order.create` (sama dengan ubah open bill) |

## Konsekuensi

- Positif: bayar terpisah per orang / gabung meja tanpa input ulang.
- Negatif: bill dengan diskon/voucher/stempel/pembayaran harus dibereskan dulu; harga ikut harga terkini.
- Status order baru `merged` (label "Digabung"); laporan hanya menghitung `completed`, jadi tidak berubah.
