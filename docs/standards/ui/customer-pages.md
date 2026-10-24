# Halaman pelanggan (pesan dari meja & member)

Pola tampilan `/order/t/{token}` dan `/m/{bisnis}` (ADR 0027 §6, SPEC Q137). Satu bahasa visual dengan panel owner,
tetapi mobile-first. Kode: `resources/css/self-order.css` (entri Vite tersendiri, kelas `.so-*`), komponen
`resources/views/components/so/*`.

## Tema

| Hal | Aturan |
|---|---|
| Token | `filament/shared/tokens.css` + `TenantAppearance::css()` per bisnis; **tanpa hex** di CSS selain token status `--so-*` (palet badge panel) & putih kartu QR — dijaga `SelfOrderAppearanceTest` |
| `theme-color` | `TenantAppearance::hex()` (bilah status HP) |
| Mode | Terang / gelap / ikuti perangkat dari setelan bisnis, dipasang di `<head>` sebelum render; tanpa toggle |
| Merek | Logo bisnis atau inisial di kotak `--primary-lt`; chip meja `--primary-btn` |

## Ukuran (sama dengan panel owner)

Kontrol = kasir web (Q138): tinggi 40px (`--control-h`, di-override di `self-order.css`), field & tombol 14px,
tombol 600 radius 10px padding 16px, label 12.5px / 500, bantuan & error 12px, badge 20px, tombol ikon 40px
(32px `--control-h-sm` di dalam baris: stepper kartu, Tambah). Area sentuh diperluas `::after` inset −6px tanpa mengubah tampilan.

Tanpa polling (Q138): daftar Pesananku & layar menunggu bayar dimuat ulang saat HP kembali ke tab (`visibilitychange`)
atau lewat tombol **Perbarui** / **Cek status pembayaran**. Struk digital (`<details wire:ignore.self>`) tidak tertutup saat dirender ulang.
Tidak ada ukuran 40/44px; satu-satunya angka besar = nomor pesanan di layar "Tunjukkan ke kasir" (28px mono, mengecil di HP sempit).

## Kerangka

```
Header 56px menempel: logo · bisnis/outlet · chip meja · Masuk | chip member ▾ (menu akun: Kartu member, Tentang, Keluar)
Konten maks. 560px, padding 16px, jarak kartu 12px (kartu radius 12px, border, tanpa bayangan)
Bilah aksi (keranjang / Bayar) menempel di atas navigasi bawah
Navigasi bawah: Menu · Keranjang · Pesananku · Profil (ikon 20px + label 11px + badge jumlah, safe-area)
```

## Komponen

| Komponen | Kelas / file | Catatan |
|---|---|---|
| Field berikon | `<x-so.input icon=… />` | Ikon kiri 16px, `type="password"` mendapat tombol tampilkan; error di bawah field |
| Kartu produk | `.so-product` | Mode **Kotak** (min. 3 kolom di HP) / **Baris** (gambar 56px), dipilih pelanggan & disimpan di `localStorage`; placeholder seragam bila tanpa gambar; `+` → stepper `− n +` |
| Tab kategori | `.so-cats` + `.so-tab` | Menempel di bawah header, scroll-spy (`x-intersect`), garis bawah 2px primary |
| Bottom sheet | `.so-sheet` | Opsi produk & pilihan bayar: pegangan geser, `x-trap`, Esc/✕/latar menutup |
| Keranjang | `.so-cart-row`, `.so-extra` | Baris ringkas (ketuk = ubah), catatan & voucher berupa baris yang bisa dibuka |
| Lini waktu | `.so-timeline` | Selesai hijau bercentang, aktif primary berdenyut, jam per tahap |
| Toast | `.so-toast` | Satu aksi (Urungkan), 5 detik, di atas bilah bawah |
| Keadaan kosong | `.so-empty` | Ikon dalam lingkaran `--primary-lt`, judul 15px, kalimat, satu aksi |

## Keadaan khusus

QR tidak aktif (halaman 404 netral), self-order mati / di luar jam (empty-state di meja), kasir belum buka (banner +
Bayar nonaktif), Midtrans belum diatur (metode disembunyikan), koneksi putus (`wire:offline` "Koneksi terputus…"),
request lambat (bilah tipis di bawah header), batas tercapai (pesan ramah tanpa alasan internal).

## Layar antrean staf

Kasir web `/cashier/queue` memakai sistem kasir web (`.pos-*`, Q131): tiga kolom Antre & disiapkan · Siap diantar ·
Diantar (1 jam), ≤ 900px menjadi tab; kartu `.pos-ticket` (chip meja, umur kuning > 15 / merah > 30 menit, satu tombol
langkah, ⋯ detail); toast kasir dengan aksi **Urungkan**. Dashboard **Antrean pesanan** = tabel standar owner
([table.md](components/table.md)) + 4 kartu ringkasan ([stat-widget.md](components/stat-widget.md)).
