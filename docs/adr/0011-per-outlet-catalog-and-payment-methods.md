# ADR 0011: Menu & metode pembayaran per outlet — katalog bersama, dipilih per outlet

- Status: Diterima
- Tanggal: 2026-10-10
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q39 (diganti), Q42–Q45, ADR 0010 (multi-outlet)

## Konteks

Setelah ADR 0010, yang sudah per outlet: transaksi, shift, perangkat, laporan, stok, dan tanda
habis/tersedia produk. Produk, kategori, grup opsi, dan metode pembayaran masih satu untuk seluruh
bisnis (Q39). Cabang kedua bisa punya menu atau alat bayar yang berbeda (mis. belum ada EDC), dan opsi
(mis. topping) bisa habis di satu outlet saja — saat ini opsi sama sekali tidak bisa ditandai habis.

## Pilihan

| Opsi | Cara kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| A. Katalog terpisah per outlet | Setiap outlet punya produk/kategori/grup opsi sendiri | Bebas penuh | Data dobel; ubah harga di tiap outlet; laporan produk lintas outlet sulit |
| B. Katalog bersama + atur per outlet | Satu katalog bisnis; tiap outlet memilih produk yang dijual | Satu sumber data, menu dibuat sekali, laporan gabungan rapi | Perlu UI pilihan outlet di form produk |
| C. Tetap seperti sekarang | Semua outlet menu sama (Q39) | Paling sederhana | Tidak cocok bila menu cabang berbeda |

## Keputusan

Kami memilih **B, tanpa harga per outlet**.

| Topik | Keputusan |
|---|---|
| Produk | `outlet_product.is_listed` (default `true`): produk dijual di outlet ini. Form produk: centang "Dijual di outlet". `is_listed = false` → tidak dikirim di `/catalog`, ditolak saat checkout. Berbeda dari `is_available` (dijual tapi sedang habis, tetap tampil) |
| Harga | **Tetap satu harga per produk** untuk semua outlet. Harga per outlet ditunda (backlog); `OrderCalculator` tidak berubah |
| Kategori | Tidak diatur per outlet; dikirim ke outlet bila ada minimal satu produk yang dijual di sana |
| Grup opsi | Tetap bersama. Opsi bisa ditandai habis per outlet: tabel `outlet_option` (`is_available`). Tanpa baris = tersedia |
| Metode pembayaran | Tabel `outlet_payment_method` (`is_active`). Metode dipakai di outlet bila aktif di bisnis **dan** di outlet. Nama tetap satu per bisnis (nama per outlet ditunda) |
| API kasir | `/catalog` memakai outlet device. Checkout: produk tidak dijual / opsi habis / metode bayar nonaktif di outlet → `VALIDATION_ERROR` per field (tanpa kode error baru) |
| Outlet baru | Pilihan "Salin menu & metode bayar dari outlet …" (default outlet pertama); tanpa pilihan = semua produk dijual & semua metode aktif |
| Data lama | Migrasi: semua produk `is_listed = true`, semua metode aktif di semua outlet → tenant lama tidak berubah perilaku |

## Konsekuensi

- Positif: cabang bisa punya menu & alat bayar sendiri tanpa data dobel; laporan produk tetap gabungan.
- Negatif / risiko: setiap query katalog & checkout harus memfilter outlet (fail-closed: tanpa baris
  `outlet_product` = tidak dijual); dua tabel pivot baru yang wajib tenant-scoped.
- Yang diubah: migrasi, `GetCatalog`, `ResolveOrderLines`, `AllocatePayments`, `SaveProduct`,
  `CreateOutlet`, `CreateDefaultPaymentMethods`, `UpdatePaymentMethod`, form produk/metode bayar/outlet
  di dashboard, dokumen API katalog & checkout.
- Urutan kerja: `docs/PROGRESS.md` → "Menu per outlet".
