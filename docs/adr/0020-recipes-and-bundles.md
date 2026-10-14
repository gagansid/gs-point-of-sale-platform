# ADR 0020: Bahan baku, resep, dan paket (bundling)

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q97–Q99, ADR 0015 (stok masuk, modal rata-rata tertimbang), Q53 (snapshot HPP)

## Konteks

Stok hanya untuk produk jadi (ADR 0015). Di kafe yang habis adalah bahan (kopi, susu, gula), bukan "Es Kopi Susu".
HPP diambil dari `cost_price` yang diisi manual. Paket hemat (Nasi Goreng + Es Teh) belum bisa dibuat tanpa produk
terpisah yang stoknya tidak terhubung.

## Keputusan

| Topik | Keputusan |
|---|---|
| Jenis produk | `products.kind`: `regular` (dijual), `ingredient` (bahan baku: tidak dijual, tidak tampil di kasir/katalog/self-order, stok selalu dilacak), `bundle` (paket: dijual, stok dari isinya) |
| Satuan | `products.unit` (mis. `gram`, `ml`, `pcs`; bawaan `pcs`). Stok tetap bilangan bulat dalam satuan itu — kopi 1 kg dicatat 1000 gram |
| Resep / isi paket | `product_components` (produk → komponen, qty bulat > 0). Komponen: produk biasa atau bahan, bukan paket, bukan dirinya sendiri. Produk biasa yang punya resep dan paket **tidak** melacak stok sendiri |
| Opsi berbahan | `option_components` (mis. "Extra shot" = 18 gram kopi) dipotong per item yang memilih opsi itu |
| Potong stok | Saat order selesai: komponen × qty (rekursif 1 tingkat), opsi berbahan × qty; tanpa resep = produk itu bila dilacak. Riwayat stok `sale` per komponen, `reference_id` = order |
| Void | Membalik **persis** riwayat `sale` order itu (resep yang diubah setelah transaksi tidak memengaruhi) |
| Retur (ADR 0018) | Stok kembali = konsumsi resep saat ini × qty retur |
| HPP | `order_items.unit_cost` = Σ modal komponen × qty (+ opsi berbahan); tanpa resep = `cost_price` produk |
| Modal bahan | Bahan dibeli lewat Stok masuk → modal rata-rata tertimbang (ADR 0015) |

## Konsekuensi

- Positif: stok bahan berkurang otomatis, HPP & laba kotor mengikuti modal bahan, paket memotong stok isinya.
- Negatif: satuan bulat (tidak ada desimal); resep 1 tingkat (paket tidak boleh berisi paket); import Excel tetap
  produk biasa.
