# ADR 0015: Stok masuk, stok opname, transfer antar outlet — produk jadi, modal rata-rata tertimbang

- Status: Diterima
- Tanggal: 2026-10-12
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q79–Q83, Q14 (riwayat stok), Q24 (stok menipis), Q40 (stok per outlet), Q53 (snapshot harga modal)

## Konteks

Stok saat ini hanya untuk **produk jadi** per outlet (`outlet_product.stock_qty`) dan hanya bisa diubah lewat
penyesuaian manual satu produk (`AdjustStock`). Owner perlu mencatat barang datang dari supplier beserta harga
belinya, menghitung fisik stok berkala, dan memindahkan stok antar cabang. Harga modal (`products.cost_price`)
masih diisi manual, padahal laporan laba kotor (Q53) bergantung padanya.

## Pilihan

| Topik | Opsi | Dipilih |
|---|---|---|
| Objek stok | Produk jadi · bahan baku + resep · keduanya | **Produk jadi** (bahan baku & resep tetap Fase 2) |
| Alur beli | Stok masuk saja · PO lalu terima · PO + utang supplier | **Stok masuk saja** |
| Harga modal | Rata-rata tertimbang · harga beli terakhir · manual | **Rata-rata tertimbang** |
| Transfer | Satu langkah · dua langkah (kirim → terima) | **Dua langkah** |
| Tempat | Dashboard · + kasir web · + API | **Dashboard saja** (API/kasir menyusul bila perlu) |

## Keputusan

### Dokumen stok

| Dokumen | Alur | Riwayat stok |
|---|---|---|
| **Stok masuk** | Draf (bisa diubah) → **diposting** (stok outlet bertambah, modal dihitung ulang, tidak bisa diubah) → bisa **dibatalkan** dengan alasan (stok dikurangi lagi) | `purchase` / `purchase_void` |
| **Stok opname** | Draf lembar hitung per outlet (semua produk lacak stok atau per kategori) → isi qty fisik → **diposting**: selisih = fisik − stok saat posting | `opname` (+/−) |
| **Transfer** | Draf → **dikirim** (stok asal berkurang, "dalam perjalanan") → **diterima** di tujuan (qty diterima boleh berbeda, selisih tercatat) · batal hanya sebelum diterima (stok asal kembali) | `transfer_out` / `transfer_in` / `transfer_cancel` |

- Nomor dokumen per outlet per hari lokal: `{kode outlet}-{SM|SO|TR}-{YYMMDD}-{urut}` (tabel `document_sequences`,
  baris dikunci seperti nomor order).
- Semua dokumen milik tenant & outlet (fail-closed seperti order, ADR 0005/0010); `id` dari klien = idempotency key.
- Stok tetap boleh minus (SPEC Stok); qty dokumen bilangan bulat > 0 (opname ≥ 0).
- Setiap baris riwayat `stock_movements` menunjuk dokumennya (`reference_id`).

### Harga modal rata-rata tertimbang

Saat stok masuk diposting, per produk:

```
modal baru = (stok semua outlet × modal lama + qty beli × harga beli) ÷ (stok semua outlet + qty beli)
```

- `cost_price` berlaku per produk (bukan per outlet), jadi stok dijumlahkan dari semua outlet; stok minus dihitung 0;
  modal lama kosong = harga beli.
- Hasil dibulatkan 2 desimal; modal sebelum & sesudah disimpan di baris dokumen (jejak audit).
- Pembatalan stok masuk **tidak** memutar balik modal (modal sesudahnya mungkin sudah dipakai transaksi lain);
  owner bisa mengoreksi harga modal di form produk. Transaksi lama aman karena HPP di-snapshot per order (Q53).
- Opname & transfer tidak mengubah modal.

### Supplier

Data bisnis (semua outlet): nama (unik per bisnis), telepon, email, alamat, catatan, aktif. Dipilih (opsional) di
stok masuk. Tanpa utang/pembayaran supplier.

### Izin

| Izin | Owner | Manager | Supervisor | Kasir |
|---|---|---|---|---|
| `stock.receive` (stok masuk: buat, posting, batal) | ✅ | ✅ | ✅ | ❌ |
| `stock.opname` | ✅ | ✅ | ❌ | ❌ |
| `stock.transfer` | ✅ | ✅ | ❌ | ❌ |
| `supplier.manage` | ✅ | ✅ | ❌ | ❌ |

Supervisor (dashboard hanya Laporan, Q50) mendapat menu **Stok → Stok masuk** untuk outlet yang ditugaskan.

## Konsekuensi

- Positif: stok & harga modal mengikuti barang datang; laba kotor lebih akurat; selisih opname & transfer terlihat.
- Negatif: rata-rata tertimbang lintas outlet mengasumsikan harga modal sama di semua cabang; tanpa PO & utang
  supplier, rekonsiliasi tagihan supplier di luar sistem.
- Netral: aplikasi Flutter tidak berubah (dashboard saja).

## Tahapan

| Tahap | Isi |
|---|---|
| I1 | Supplier, stok masuk (draf, posting, batal), modal rata-rata tertimbang, nomor dokumen |
| I2 | Stok opname |
| I3 | Transfer antar outlet |
| I4 | Laporan stok & pembelian per supplier, export |
