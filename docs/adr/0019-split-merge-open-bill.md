# ADR 0019: Pecah & gabung open bill

- Status: Diterima
- Tanggal: 2026-10-14 (revisi 2026-10-16: diskon manual ikut dibagi)
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
| Syarat | Keduanya open bill (`open`) di outlet perangkat, **belum ada pembayaran**, tanpa voucher atau kartu stempel. Lepas dulu bila ada → `VALIDATION_ERROR` |
| Diskon | **Diskon manual ikut** (revisi 2026-10-16): pecah — diskon item dibagi proporsional qty (sisa pembulatan di bill asal), diskon order persen berlaku di kedua bill, diskon order nominal tetap di bill asal; gabung — diskon item ikut, diskon order persen sama tetap persen, selain itu jadi nominal = jumlah potongan order kedua bill. Approval diskon lama (`approved_by`) ikut tanpa PIN ulang karena pembagian tidak menambah total diskon |
| Member | Pecah: member tetap di bill asal, bill baru tanpa member. Gabung: member tujuan, atau member sumber bila tujuan tanpa member; dua member berbeda → `VALIDATION_ERROR` |
| Harga | Kedua bill dihitung ulang dengan harga & setelan saat ini lewat jalur yang sama dengan ubah open bill (`SaveOpenBill`) |
| Izin | `order.create` (sama dengan ubah open bill) |

## Konsekuensi

- Positif: bayar terpisah per orang / gabung meja tanpa input ulang.
- Negatif: bill dengan voucher/stempel/pembayaran harus dibereskan dulu; harga ikut harga terkini. Persen diskon bill hasil pecah bisa
  di atas batas role pelaku (mis. item berdiskon dipindah sendiri) — tetap sah karena approval/izin awal berlaku untuk nominal yang sama.
- Status order baru `merged` (label "Digabung"); laporan hanya menghitung `completed`, jadi tidak berubah.
