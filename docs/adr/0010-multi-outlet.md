# ADR 0010: Multi-outlet — outlet per tenant, penugasan karyawan banyak-ke-banyak, stok per outlet

- Status: Diterima
- Tanggal: 2026-10-10
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q36–Q41, ADR 0001, ADR 0005, utang teknis T7

## Konteks

SPEC MVP: satu outlet per bisnis; multi-outlet dijadwalkan Fase 2. Owner yang punya lebih dari satu
cabang perlu menambah outlet, dan manager/supervisor bisa memegang lebih dari satu outlet. Fondasi
data sebagian siap (orders, shifts, devices, order_sequences ber-`outlet_id`; setelan pajak/struk per
outlet), tetapi:

- `users.outlet_id` hanya satu outlet;
- `products.stock_qty` dan `products.is_available` satu angka untuk seluruh bisnis;
- dashboard memakai `CurrentOutlet` = outlet pertama (T7); laporan tanpa filter outlet.

Mengubah struktur karyawan & stok setelah ada data pelanggan nyata lebih berisiko → dikerjakan
**sebelum** kasir web dan deploy pilot.

## Pilihan

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| A. Tetap 1 outlet, cabang = tenant terpisah | Tanpa perubahan | Owner login berkali-kali, laporan tidak tergabung, katalog diisi ulang |
| B. Multi-outlet dalam satu tenant, katalog bersama | Satu login & katalog, laporan gabungan/per outlet | Perlu penugasan outlet, stok per outlet, scope outlet |
| C. B + katalog/harga terpisah per outlet | Fleksibel penuh | Kompleks untuk UMKM; tidak dibutuhkan sekarang |

## Keputusan

Kami memilih **B**.

| Topik | Keputusan |
|---|---|
| Outlet | Owner menambah/mengelola outlet di Pengaturan → Outlet. Kode outlet unik per tenant, tidak bisa diubah. Outlet dinonaktifkan, tidak dihapus. Jumlah maksimal outlet per tenant diatur admin (`tenants.max_outlets`, SaaS; jual putus tanpa batas) |
| Penugasan | Tabel `outlet_user` (banyak-ke-banyak) menggantikan `users.outlet_id`. **Owner** = semua outlet otomatis. Manager, supervisor, **dan kasir** boleh lebih dari satu outlet |
| Hak akses | Data per outlet (order, shift, laporan, perangkat, stok) hanya untuk outlet yang ditugaskan; outlet lain → 404 seperti isolasi tenant (fail-closed). Permission tetap dari role |
| Dashboard | Pemilih outlet di topbar: "Semua outlet" (owner/manager multi) atau satu outlet; disimpan di session. Menggantikan `CurrentOutlet` = outlet pertama (T7) |
| Katalog | **Dipakai bersama** semua outlet: kategori, produk, grup opsi, **harga sama** |
| Ketersediaan & stok | **Per outlet**: tabel `outlet_product` (`is_available`, `stock_qty`, `min_stock`). Lacak stok tetap setting produk |
| Tablet/API | Device terikat satu outlet (sudah); katalog, stok, shift, order memakai outlet device. Login PIN hanya karyawan yang ditugaskan ke outlet device |
| Laporan | Filter outlet; "Semua outlet" = gabungan |

## Konsekuensi

- Positif: owner multi-cabang dalam satu akun; data cabang terisolasi untuk manager/kasir.
- Negatif / risiko: migrasi data (`users.outlet_id` → `outlet_user`, stok produk → outlet pertama),
  banyak query & test berubah; perlu scope outlet baru yang fail-closed.
- Yang diubah: migrasi, `CurrentOutlet`, `PinUsers`, `AdjustStock`, `OrderWriter` (potong stok per
  outlet), `GetCatalog`, laporan, Profil outlet → Outlet (daftar), Karyawan (pilih outlet), API.
- Urutan kerja: `docs/PROGRESS.md` → "Multi-outlet".
