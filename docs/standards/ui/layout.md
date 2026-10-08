# Layout & Navigasi

## 1. Kerangka panel

```
┌────────────┬───────────────────────────────────────────────────────┐
│ logo   [‹] │ Breadcrumb  Produk › Es Kopi Susu                     │
│ cari       │ Judul halaman                       [Aksi sekunder] [Aksi utama] │
│ grup menu  │                                                       │
│  terang    │ ┌───────────────────────────────────────────────────┐ │
│  296px     │ │ Konten: tabel / form / widget dalam kartu putih   │ │
│            │ └───────────────────────────────────────────────────┘ │
│ [👤 user ›]│                                                       │
└────────────┴───────────────────────────────────────────────────────┘
```

Tanpa topbar (`->topbar(false)`): logo, tombol ciutkan, pencarian global, dan menu user
(kartu nama + email) berada di sidebar. Di bawah `lg` tombol ☰ muncul di kiri atas konten.

## 2. Navigasi `/dashboard`

Urutan dan grup menu tetap. Ikon Heroicons outline (lihat [components/icon.md](components/icon.md)).

| Grup | Menu | Ikon | Permission |
|---|---|---|---|
| — | Beranda | `home` | `report.view` |
| Transaksi | Penjualan | `receipt-percent` | `order.view_all` |
| Transaksi | Shift | `clock` | `shift.view_all` |
| Katalog | Produk | `cube` | `product.manage` |
| Katalog | Kategori | `tag` | `product.manage` |
| Katalog | Grup Opsi | `adjustments-horizontal` | `product.manage` |
| Katalog | Stok | `archive-box` | `stock.adjust` |
| Laporan | Laporan | `chart-bar` | `report.view` |
| Pengaturan | Karyawan | `users` | `user.manage` |
| Pengaturan | Perangkat | `device-tablet` | `device.manage` |
| Pengaturan | Metode Pembayaran | `credit-card` | `payment_method.manage` |
| Pengaturan | Pengaturan Outlet | `building-storefront` | `outlet.settings` |

Menu tanpa permission disembunyikan (`canAccess()` / `canViewAny()` memakai `can()`), bukan
ditampilkan lalu ditolak.

## 3. Navigasi `/admin`

| Grup | Menu | Ikon |
|---|---|---|
| — | Beranda | `home` |
| Pelanggan | Tenant | `building-office-2` |
| Aplikasi | Versi Aplikasi | `device-phone-mobile` |
| Aplikasi | Pengumuman | `megaphone` |
| Sistem | Maintenance Mode | `wrench-screwdriver` |
| Sistem | Backup | `circle-stack` |
| Sistem | Log Viewer | `document-text` |
| Sistem | Queue | `queue-list` |
| Sistem | Admin | `shield-check` |

## 4. Anatomi halaman

| Jenis halaman | Isi | Aksi utama (kanan atas) |
|---|---|---|
| **Daftar** (List) | Tabel dengan toolbar: pencarian, filter, tab status | `+ Tambah {objek}` |
| **Form** (Create/Edit) | Section bertumpuk; kolom kanan 360px untuk ringkasan (opsional) | `Simpan` di bawah form, kanan |
| **Detail** (View) | Infolist dalam section + relasi (tabel item, pembayaran) | Aksi kontekstual (`Cetak ulang`, `Void`) |
| **Laporan** | Filter rentang tanggal di atas → stat widget → chart → tabel | `Export Excel` |
| **Pengaturan** | Form satu kolom, maks. 760px, dikelompokkan section | `Simpan` |

Aturan:

- Maksimal **satu** tombol primary per halaman. Aksi lain `gray`/outline.
- Aksi berbahaya (`Void`, `Hapus`, `Cabut akses`) selalu `danger` + konfirmasi, tidak pernah primary.
- Judul halaman = nama menu (daftar) atau nama objek (detail/edit), bukan "Edit Product".
- Breadcrumb aktif di semua halaman selain Beranda.

## 5. Grid & lebar

| Konteks | Aturan |
|---|---|
| Konten utama | Lebar penuh (`MaxWidth::Full`), padding 24px |
| Form | `columns(2)` di ≥ `md`, 1 kolom di mobile |
| Form pengaturan | Maks. 760px |
| Form + panel info | Grid `1fr 360px` di ≥ `lg` |
| Widget beranda | 4 stat per baris di ≥ `lg`, 2 di `md`, 1 di mobile |

## 6. Responsif

- Sidebar mengambang (jarak 16px, radius 12px) di ≥ `lg`; menjadi drawer tinggi penuh di bawah `lg`
  (tablet & HP) dan dibuka dengan tombol ☰.
- Tabel: kolom prioritas rendah memakai `->toggleable(isToggledHiddenByDefault: true)` atau
  `->visibleFrom('md')`. Kolom nama & nominal selalu terlihat.
- Toolbar membungkus ke baris baru; kotak pencarian menjadi lebar penuh.
- Modal di mobile: padding 12px, tinggi maks. layar − 32px, hanya body yang di-scroll.
