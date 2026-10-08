# Design Tokens

Satu-satunya sumber nilai visual. Diturunkan dari `gs-task-tracker` (`brand.css` + `gentelella.css`)
dan disesuaikan untuk POS. Di kode, token diterapkan lewat `->colors()` panel Filament dan
variabel CSS di `resources/css/filament/{panel}/theme.css` (lihat [filament-theme.md](filament-theme.md)).

## 1. Warna brand

| Token | Hex | Pemakaian |
|---|---|---|
| `--brand-navy` | `#1B2A55` | Sidebar, logo, judul brand |
| `--brand-gray` | `#8A919E` | Bagian "gs" pada wordmark, aksen sekunder |
| `--brand-mist` | `#B8C0D4` | Teks sidebar, aksen di latar gelap |

## 2. Warna semantik (palet Filament)

| Peran Filament | Hex dasar | Dipakai untuk |
|---|---|---|
| `primary` | `#2D4282` | Aksi utama, link, fokus, item aktif, checkbox tercentang |
| `gray` | Palet `Slate` | Teks, border, latar netral |
| `success` | `#2FB344` | Selesai, aktif, lunas, selisih kas 0 |
| `warning` | `#F59F00` | Trial, stok menipis, perlu perhatian |
| `danger` | `#D63939` | Void, suspended, stok ≤ 0, selisih kas minus, aksi hapus |
| `info` | `#066FD1` | Open bill, shift berjalan, informasi netral |

Primary sengaja lebih muda dari navy logo agar tombol tidak terasa berat (sama seperti referensi).

### Warna permukaan (light)

| Token | Nilai | Pemakaian |
|---|---|---|
| `--body-bg` | `#F5F7FB` | Latar halaman |
| `--surface` | `#FFFFFF` | Kartu, tabel, modal |
| `--surface-secondary` | `#F9FAFB` | Header tabel, baris zebra, area sekunder |
| `--border` | `#E6E7EB` | Border kartu & input |
| `--border-light` | `#EFF0F3` | Pemisah di dalam kartu |
| `--text` | `#1E2633` | Teks utama |
| `--text-secondary` | `#626D7D` | Label, teks pendukung |
| `--text-muted` | `#7E8896` | Hint, placeholder, metadata |
| `--text-disabled` | `#C0C7CF` | Nilai kosong, disabled |

### Warna permukaan (dark)

| Token | Nilai |
|---|---|
| `--body-bg` | `#0F1623` |
| `--surface` | `#1A2332` |
| `--surface-secondary` | `#141D2B` |
| `--border` | `rgba(255,255,255,.08)` |
| `--text` | `#E6EBF2` |
| `--text-secondary` | `#B3BCCB` |
| `--text-muted` | `#8A93A3` |
| `primary` (dark) | `#B8C0D4` (mist) |

### Warna latar transparan (`-lt`)

Untuk latar badge, ikon konfirmasi, dan hover aksi: warna semantik dengan alpha **6%** (light)
atau **16%** (dark). Hover tombol ikon: alpha **12%**.

## 3. Sidebar

Gaya terang mengacu Untitled UI (sidebar mengambang, item 16px/600). Dipakai kedua panel;
`/admin` dibedakan oleh badge "Super Admin" di logo.

| Token | Light | Dark | Pemakaian |
|---|---|---|---|
| `--sidebar-bg` | `#FAFAFA` | `#13161B` | Latar sidebar |
| `--sidebar-border` | `#E9EAEB` | `rgba(255,255,255,.08)` | Garis tepi sidebar & kartu user |
| `--sidebar-text` | `#424242` | `#CECFD2` | Label item |
| `--sidebar-text-active` | `#0A0A0A` | `#F7F7F7` | Label item aktif, nama user |
| `--sidebar-text-muted` | `#535353` | `#94979C` | Email user |
| `--sidebar-icon` | `#737373` | `#85888E` | Ikon item, tombol grup |
| `--sidebar-accent` | `#5850EC` | `#8B85FF` | Ikon item aktif |
| `--sidebar-hover` | `#F4F4F4` | `rgba(255,255,255,.04)` | Hover item |
| `--sidebar-active-bg` | `#F1F1F1` | `rgba(255,255,255,.07)` | Latar item aktif |
| `--sidebar-group-label` | `#717171` | `#94979C` | Label grup (12px/600, uppercase) |
| `--sidebar-badge-bg` | `#F0F0F0` | `#22262F` | Badge angka di item |
| `--sidebar-badge-text` | `#343434` | `#CECFD2` | Teks badge |
| `--sidebar-card-bg` | `#FFFFFF` | `#1A1D23` | Kartu user di bawah sidebar |

| Ukuran | Nilai |
|---|---|
| Lebar | 296px (`18.5rem`); ciut: hanya ikon |
| Mengambang (≥ `lg`) | jarak 16px dari tepi layar, radius 12px, border 1px |
| Drawer (< `lg`) | tinggi penuh, radius 12px di sisi kanan |
| Item | tinggi 42px, padding 9px 14px, radius 6px, jarak 1px, ikon 20px, gap 12px |
| Label item | Inter 16px / 600, line-height 24px |
| Label grup | 12px / 600, uppercase, `letter-spacing .02em`, jarak antar-grup 24px |
| Kartu user | avatar 40px, nama 14px/600, email 14px/400, padding 12px, radius 10px |

## 4. Tipografi

| Token | Nilai |
|---|---|
| Font UI | `Inter`, fallback `-apple-system, Segoe UI, Roboto, sans-serif` |
| Font brand | `Nunito` (hanya wordmark logo) |
| Font mono | `SF Mono, Monaco, Consolas, monospace` (nomor order, kode, SKU) |
| Angka | `font-variant-numeric: tabular-nums` untuk semua nominal & kuantitas |

| Skala | Ukuran / berat | Pemakaian |
|---|---|---|
| Page title | 20px / 600 | Judul halaman |
| Section title | 16px / 600 | Judul kartu/section |
| Body | 14px / 400 | Teks umum |
| Control | 13px / 500 | Tombol, input, sel tabel |
| Small | 12px / 500–600 | Badge, hint, label kolom |
| Overline | 12px / 600, uppercase, `letter-spacing .04em`, `text-muted` | Judul grup form |
| Stat value | 28px / 600, tabular | Angka utama widget |

## 5. Spacing

Skala 4px: `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64`.

| Pemakaian | Nilai |
|---|---|
| Jarak ikon ↔ teks dalam tombol | 6px |
| Jarak antar tombol bersebelahan | 8px |
| Padding sel tabel | 12px × 16px |
| Padding kartu / section | 24px |
| Jarak antar field form | 16px |
| Jarak antar section | 24px |
| Padding halaman | 24px desktop, 12px mobile |

## 6. Ukuran kontrol

| Token | Nilai |
|---|---|
| `--control-h` | 36px (default tombol, input, select) |
| `--control-h-sm` | 30px (tombol di tabel/toolbar padat) |
| `--control-h-lg` | 40px (aksi utama halaman form) |
| `--control-radius` | 8px |
| `--control-font` | 13px |
| Tombol ikon baris | 30 × 30px, ikon 16px |

## 7. Radius & bayangan

| Token | Nilai | Pemakaian |
|---|---|---|
| `--radius-sm` | 4px | Elemen kecil di dalam komponen |
| `--radius` | 6px | Tombol ikon |
| `--radius-control` | 8px | Tombol, input |
| `--radius-card` | 12px | Kartu, section, modal |
| `--radius-pill` | 999px | Badge |
| `--shadow-card` | `0 0 0 1px rgba(4,32,69,.08), 0 2px 4px rgba(30,38,51,.04)` | Kartu |
| `--shadow-pop` | `0 8px 24px rgba(30,38,51,.14)` | Dropdown, date picker, modal |
| `--focus-ring` | `0 0 0 3px primary/15%, 0 0 0 1px primary` | Fokus keyboard semua kontrol |

## 8. Motion

Transisi `150ms ease` untuk warna, border, bayangan. Tidak ada animasi dekoratif.
Hormati `prefers-reduced-motion`.

## 9. Breakpoint

Mengikuti Tailwind/Filament: `sm 640` · `md 768` · `lg 1024` · `xl 1280`. Sidebar ciut di bawah `lg`.
