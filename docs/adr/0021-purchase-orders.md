# ADR 0021: Purchase Order ke supplier

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q100–Q101, ADR 0015 (stok masuk), ADR 0020 (bahan baku)

## Konteks

ADR 0015 sengaja hanya mencatat **barang datang** (Stok masuk). Setelah ada bahan baku (ADR 0020), pembelian ke
supplier menjadi rutin dan owner perlu mencatat pesanan sebelum barang datang, melihat yang belum diterima, dan
menerima sebagian.

## Keputusan

| Topik | Keputusan |
|---|---|
| Dokumen | `purchase_orders` per outlet + supplier wajib, nomor `{outlet}-PO-{YYMMDD}-{urut}`, item produk lacak stok (qty, harga beli perkiraan) |
| Alur | **Draf** (ubah/hapus) → **Dikirim** (terkunci) → **Diterima sebagian** / **Diterima** · **Dibatalkan** (alasan, hanya sebelum diterima penuh) |
| Penerimaan | Aksi **Terima barang** membuat draf **Stok masuk** berisi sisa qty & harga PO (`stock_receipts.purchase_order_id`); qty/harga bisa diubah sebelum posting. Posting menambah `received_qty` PO per produk (boleh lebih dari pesanan); membatalkan stok masuk itu mengurangi lagi. Status PO dihitung ulang dari `received_qty` |
| Izin | Baru `purchase.manage` (owner, manager) untuk PO; penerimaan memakai izin Stok masuk (`stock.receive`) |
| Tempat | Dashboard saja (**Stok → Purchase order**), tanpa API (sama dengan ADR 0015) |

## Konsekuensi

- Positif: pesanan ke supplier tercatat, sisa yang belum datang terlihat, harga & modal tetap lewat Stok masuk.
- Negatif: tanpa utang supplier & cetak PO (bisa ditambah kemudian); PO dibatalkan setelah diterima sebagian tidak
  membatalkan stok masuk yang sudah diposting.
