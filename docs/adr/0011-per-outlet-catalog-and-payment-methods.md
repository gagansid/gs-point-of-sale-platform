# ADR 0011 — Menu & metode pembayaran per outlet

- Status: **Diusulkan** (menunggu keputusan pemilik produk, belum dikerjakan)
- Tanggal: 2026-10-10
- Terkait: ADR 0010 (multi-outlet), SPEC Q39 (katalog & harga sama untuk semua outlet)

## Konteks

Setelah ADR 0010, yang sudah per outlet: transaksi, shift, perangkat, laporan, stok, dan tanda
habis/tersedia. Produk, kategori, grup opsi, dan metode pembayaran masih satu untuk seluruh bisnis.
Cabang kedua bisa punya menu, harga, atau alat bayar yang berbeda (mis. belum ada EDC).

## Pilihan

| Opsi | Cara kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| A. Katalog terpisah per outlet | Setiap outlet punya produk/kategori/grup opsi sendiri | Bebas penuh | Data dobel; ubah harga di tiap outlet; laporan produk lintas outlet sulit |
| **B. Katalog bersama + atur per outlet** (usulan) | Satu katalog bisnis; tiap outlet memilih produk yang dijual, opsional harga khusus | Satu sumber data, menu dibuat sekali, laporan gabungan rapi | Perlu UI pilihan outlet di form produk |
| C. Tetap seperti sekarang | Semua outlet menu sama (Q39) | Paling sederhana | Tidak cocok bila menu cabang berbeda |

## Usulan (opsi B)

- **Produk:** `outlet_product` (sudah ada) ditambah `is_listed` (dijual di outlet ini) dan `price`
  (nullable = harga produk). Form produk: centang "Dijual di outlet".
- **Kategori:** tidak diatur per outlet; tampil di outlet bila ada produknya yang dijual di sana.
- **Grup opsi:** tetap bersama; opsi bisa ditandai habis per outlet (`outlet_option`).
- **Metode pembayaran:** `outlet_payment_method` (`is_active`, `name` opsional per outlet,
  mis. "QRIS BCA" vs "QRIS Mandiri").
- **API kasir:** `/catalog` dan checkout memakai daftar outlet device; produk tidak dijual di outlet
  → ditolak `VALIDATION_ERROR` per item. Harga server dari harga outlet (lewat `OrderCalculator`).
- **Outlet baru:** pilihan "salin menu & metode bayar dari outlet …".

## Keputusan yang dibutuhkan

1. Opsi A, B, atau C?
2. Bila B: harga boleh berbeda per outlet sekarang, atau ditunda (hanya pilih produk yang dijual)?
3. Metode bayar: cukup aktif/nonaktif per outlet, atau juga nama berbeda per outlet?

Setelah diputuskan: ubah status ADR ini, tambah SPEC Q42+, lalu implementasi + test.
