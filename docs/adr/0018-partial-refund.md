# ADR 0018: Retur sebagian per item

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q92–Q94, ADR 0017 (kas keluar laci), ADR 0013 (poin member), SPEC *Shift & void*

## Konteks

Satu-satunya pembatalan saat ini adalah **void** satu order penuh, dan hanya selama shift order itu masih terbuka.
Pelanggan yang mengembalikan 1 dari 3 item, atau datang besok karena produk rusak, tidak bisa dicatat dengan benar:
kasir terpaksa void + input ulang (bila shift masih buka) atau mengeluarkan uang laci tanpa catatan.

## Pilihan

| Topik | Opsi | Dipilih |
|---|---|---|
| Satuan retur | Seluruh order · **per item & qty** | **Per item & qty** |
| Kapan | Shift yang sama · **shift mana pun, batas hari** | **Shift berjalan di perangkat pelaku, maks. 30 hari** (`pos.refund.max_days`) |
| Nilai | Harga item · **bagian proporsional dari grand total** | **Proporsional** (`OrderCalculator::refundShare`) |
| Uang keluar | Selalu tunai · **tunai atau manual** | **Tunai** (kas keluar laci shift berjalan) atau **manual** (transfer/QRIS di luar sistem) |
| Stok | Selalu kembali · **pilihan** | **Pilihan "Kembalikan ke stok"** (bawaan ya; barang rusak = tidak) |
| Izin | **Izin baru `order.refund`** | Owner, manager, supervisor; kasir 🔑 PIN approver |

## Keputusan

- Dokumen `order_refunds` (+ `order_refund_items`): order **selesai** (bukan void/open), alasan wajib, `id` dari klien =
  idempotency key, pelaku & approver tercatat. Qty retur per item ≤ qty terjual − sudah diretur.
- Nilai retur per baris = `grand_total × (line_total × qty_retur / qty) / subtotal`, 2 desimal; retur yang
  menghabiskan seluruh item order memakai sisa `grand_total − refunded_total` agar tidak ada selisih pembulatan.
  `orders.refunded_total` & `order_items.refunded_qty` diperbarui. Order tetap `completed`.
- Order yang sebagian dibayar **poin**: bagian poin dikembalikan sebagai poin (proporsional, dibulatkan ke bawah),
  sisanya dibayar sebagai uang. Poin yang didapat order ditarik proporsional (dibulatkan ke bawah). Voucher & stempel
  yang dipakai **tidak** dikembalikan pada retur sebagian. Belanja member (`total_spent`, tier) dikurangi nilai retur.
- Uang tunai: kas keluar otomatis alasan **Retur** di shift berjalan perangkat pelaku (masuk `expected_cash`, X/Z-report,
  panel Audit), tidak boleh melebihi isi laci. Manual: hanya dicatat (perlu diproses di luar sistem).
- Shift wajib terbuka di perangkat pelaku (uang keluar dari laci itu). Bisa dari aplikasi (API) & kasir web.
- Laporan: Ringkasan menambah `refund_count`, `refund_total`, `net_revenue` (omzet − retur menurut tanggal retur);
  panel Audit & ringkasan harian menampilkan retur. Laporan per produk tetap penjualan bruto.

## Konsekuensi

- Positif: pengembalian barang tercatat lengkap (uang, stok, poin), selisih laci bisa dijelaskan.
- Negatif: laporan per produk/kategori belum dikurangi retur (cukup total di Ringkasan); voucher/stempel tidak kembali
  pada retur sebagian (void penuh tetap mengembalikannya).
- Error code baru tidak ada: qty berlebih / order tidak bisa diretur / lewat batas hari → `VALIDATION_ERROR`.
