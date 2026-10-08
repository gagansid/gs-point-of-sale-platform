# Icon

**Tujuan:** mempercepat pengenalan menu dan aksi, bukan dekorasi.

## Kapan dipakai

- Menu sidebar, tombol aksi, tombol ikon baris, empty state, stat widget, modal konfirmasi.
- **Tidak** di setiap label form atau setiap sel tabel.

## Set ikon

**Heroicons** (bawaan Filament), gaya **outline** di semua tempat. Gaya `solid`/`mini` hanya untuk
indikator kecil di dalam badge.

Penulisan: `'heroicon-o-{nama}'`. Untuk ikon yang dipakai ulang, gunakan enum Filament
`Heroicon::OutlinedCube` (jika tersedia di versi terpasang) agar typo tertangkap Larastan.

## Ukuran

| Konteks | Ukuran |
|---|---|
| Tombol | 15px |
| Tombol ikon baris | 16px |
| Sidebar | 20px |
| Badge | 14px |
| Stat widget / modal konfirmasi | 18–20px |
| Empty state | 40px |

## Kamus ikon baku

Satu makna = satu ikon di seluruh aplikasi.

| Makna | Ikon |
|---|---|
| Tambah | `plus` |
| Lihat | `eye` |
| Ubah | `pencil-square` |
| Hapus | `trash` |
| Void | `x-circle` |
| Cetak / struk | `printer` |
| Export | `arrow-down-tray` |
| Import | `arrow-up-tray` |
| Filter | `funnel` |
| Cari | `magnifying-glass` |
| Nonaktifkan / aktifkan | `pause-circle` / `play-circle` |
| Cabut akses | `no-symbol` |
| Peringatan | `exclamation-triangle` |
| Sukses | `check-circle` |
| Info | `information-circle` |
| Uang / omzet | `banknotes` |
| Transaksi | `receipt-percent` |
| Stok | `archive-box` |
| Shift | `clock` |
| PIN / keamanan | `key` |

Ikon menu: lihat [layout.md](../layout.md) §2–3.

## Aksesibilitas

- Ikon dekoratif di samping teks: `aria-hidden` (bawaan Filament).
- Ikon tanpa teks wajib punya tooltip/label.

## Do / Don't

| Do | Don't |
|---|---|
| Ikon sama untuk makna sama | `trash` untuk hapus di satu tempat, `x-mark` di tempat lain |
| Outline konsisten | Campur outline & solid |
| Heroicons saja | Menambah set ikon lain (Font Awesome, dsb.) |
