# Layout & Navigasi

## 1. Kerangka panel

```
┌────────────┬───────────────────────────────────────────────────────┐
│  logo 56px │ Topbar 56px: [☰] [🔍 Cari… ⌘K]          Nama user (👤) ˅ │
│  Sidebar   ├───────────────────────────────────────────────────────┤
│  navy      │ Breadcrumb  Produk › Es Kopi Susu                     │
│  252px     │ Judul halaman                       [Aksi sekunder] [Aksi utama] │
│            │                                                       │
│  logo      │ ┌───────────────────────────────────────────────────┐ │
│  grup menu │ │ Konten: tabel / form / widget dalam kartu putih   │ │
│            │ └───────────────────────────────────────────────────┘ │
│            │ Footer: gs.POS © 2026                          v1.0.0 │
└────────────┴───────────────────────────────────────────────────────┘
```

Meniru gs-task-tracker: sidebar setinggi layar di kiri, topbar dimulai di kanan sidebar.
Topbar hanya berisi ☰, pencarian global (kiri), dan menu akun (kanan). **Pemilih outlet** `/dashboard`
(SPEC Q41) ada di atas menu sidebar, di bawah logo — hanya bila user memegang lebih dari satu outlet aktif;
sidebar ciut = ikon outlet saja (`PanelsRenderHook::SIDEBAR_NAV_START`, view `filament/dashboard/outlet-switcher`).
Kerangka diterapkan lewat `App\Filament\Shared\Layout::apply()` di kedua panel. Grup menu
tidak bisa diciutkan (`NavigationGroup::make(...)->collapsible(false)`).

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
| **Daftar** (List) | Card tabel; header card satu baris: `Daftar {objek}` · pencarian · filter · `+ Tambah` (trait `HasTableCardHeader`) | di header card, bukan kanan atas halaman |
| **Form** (Create/Edit) | Section bertumpuk; kolom kanan 360px untuk ringkasan (opsional) | `Simpan` di bawah form, kanan |
| **Detail** (View) | Infolist dalam section + relasi (tabel item, pembayaran) | Aksi kontekstual (`Cetak ulang`, `Void`) |
| **Laporan** | Filter rentang tanggal di atas → stat widget → chart → tabel | `Export Excel` |
| **Pengaturan** | Form satu kolom, maks. 760px, dikelompokkan section | `Simpan` |

Aturan:

- Maksimal **satu** tombol primary per halaman. Aksi lain `gray`/outline.
- Aksi berbahaya (`Void`, `Hapus`, `Cabut akses`) selalu `danger` + konfirmasi, tidak pernah primary.
- Judul halaman = **breadcrumb** 16px seperti gs-task-tracker: item pertama berikon menu, item terakhir tebal
  (`🏢 Tenant › Daftar`, `📦 Produk › Americano › Ubah`). Trait `HasIconBreadcrumbs` dipasang di semua halaman;
  halaman tanpa breadcrumb (Beranda, Laporan, Keamanan) memakai ikon + judul. H1 tetap ada untuk pembaca
  layar dan tampil di HP (< 640px), tempat breadcrumb disembunyikan Filament.

## 5. Grid & lebar

| Konteks | Aturan |
|---|---|
| Konten utama | Lebar penuh (`MaxWidth::Full`), padding 24px |
| Form | `columns(2)` di ≥ `md`, 1 kolom di mobile |
| Form pengaturan | Maks. 760px |
| Form + panel info | Grid `1fr 360px` di ≥ `lg` |
| Widget beranda | 4 stat per baris di ≥ `lg`, 2 di `md`, 1 di mobile |

## 6. Responsif

- Sidebar menjadi drawer di bawah `lg` (tablet & HP), dibuka dengan ☰ di topbar; di ≥ `lg` ☰ menciutkan
  sidebar menjadi 64px (ikon saja, item 48 × 36px
  di tengah, mark logo tetap tampil).
- Tabel: kolom prioritas rendah memakai `->toggleable(isToggledHiddenByDefault: true)` atau
  `->visibleFrom('md')`. Kolom nama & nominal selalu terlihat.
- Toolbar membungkus ke baris baru; kotak pencarian menjadi lebar penuh.
- Modal di mobile: padding 12px, tinggi maks. layar − 32px, hanya body yang di-scroll.
