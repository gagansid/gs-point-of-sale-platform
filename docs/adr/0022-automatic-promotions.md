# ADR 0022: Promo otomatis

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q102–Q104, ADR 0013 (voucher & diskon member), urutan perhitungan total (SPEC)

## Konteks

Potongan yang ada: diskon manual kasir (dibatasi per role), voucher (kode), diskon member/tier, gratis stempel.
Kafe juga butuh promo yang berlaku **otomatis** tanpa kode: happy hour, beli 2 gratis 1, diskon kategori, diskon
minimal belanja.

## Keputusan

| Topik | Keputusan |
|---|---|
| Jenis | `item_discount` (persen/nominal per unit untuk semua/produk/kategori tertentu), `buy_get` (beli X gratis Y: tiap X+Y unit yang memenuhi syarat, Y unit **termurah** gratis), `order_discount` (subtotal ≥ minimal → persen dengan maks., atau nominal) |
| Jadwal | Aktif, tanggal mulai/selesai, hari (Senin–Minggu), jam mulai–selesai (waktu outlet; boleh lewat tengah malam), outlet tertentu atau semua |
| Gabungan | Per baris diambil **satu** diskon item terbesar; baris yang kena diskon item tidak ikut beli X gratis Y; diskon order diambil **satu** yang terbesar. Ketiganya dijumlah dan dibatasi subtotal |
| Urutan hitung | Dasar = subtotal − gratis stempel − **promo** − diskon order manual − voucher − member. Promo tidak dihitung ke batas diskon kasir |
| Hitung | Nominal promo dihitung `App\Services\Order\AutomaticPromotions` (baca DB) lalu diteruskan ke `OrderCalculator` seperti gratis stempel; snapshot `orders.promo_discount` & `orders.promotions` (id, nama, nominal) |
| Kelola | Dashboard **Pelanggan → Promo** (izin baru `promo.manage`: owner, manager) |
| Pratinjau aplikasi | `POST /v1/orders/quote` mengembalikan total dari server (termasuk promo); `GET /catalog` memuat daftar promo aktif untuk ditampilkan |

## Konsekuensi

- Positif: promo jalan otomatis di kasir web, aplikasi, dan self-order, satu sumber perhitungan.
- Negatif: promo tidak bertumpuk di baris yang sama; open bill memakai promo saat terakhir disimpan (bukan saat bayar).
