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
| `--bg-surface` | `#FFFFFF` | Kartu, tabel, modal |
| `--bg-surface-secondary` | `#F9FAFB` | Header tabel, baris zebra, area sekunder |
| `--border-color` | `#E6E7EB` | Border kartu & input |
| `--border-color-light` | `#EFF0F3` | Pemisah di dalam kartu |
| `--text` | `#1E2633` | Teks utama |
| `--text-secondary` | `#626D7D` | Label, teks pendukung |
| `--text-muted` | `#7E8896` | Hint, placeholder, metadata |
| `--text-disabled` | `#C0C7CF` | Nilai kosong, disabled |

### Warna permukaan (dark)

| Token | Nilai |
|---|---|
| `--body-bg` | `#0F1623` |
| `--bg-surface` | `#1A2332` |
| `--bg-surface-secondary` | `#141D2B` |
| `--border-color` | `rgba(255,255,255,.08)` |
| `--text` | `#E6EBF2` |
| `--text-secondary` | `#B3BCCB` |
| `--text-muted` | `#8A93A3` |
| `primary` (dark) | `#B8C0D4` (mist) |

### Warna latar transparan (`-lt`)

Untuk latar badge, ikon konfirmasi, dan hover aksi: warna semantik dengan alpha **6%** (light)
atau **16%** (dark). Hover tombol ikon: alpha **12%**.

## 3. Sidebar

| Token | Nilai |
|---|---|
| `--sidebar-bg` | `#2D4282` (= warna tombol primary, shade 600) |
| `--sidebar-text` | `#B8C0D4` |
| `--sidebar-text-hover` | `#FFFFFF` |
| `--sidebar-hover` | `rgba(255,255,255,.06)` |
| `--sidebar-active-bg` | `rgba(184,192,212,.16)` |
| `--sidebar-active-indicator` | `inset 3px 0 0 #B8C0D4` (garis kiri item aktif) |
| `--sidebar-border` | `rgba(255,255,255,.08)` |
| `--sidebar-group-label` | `rgba(184,192,212,.6)` |
| Lebar | 252px (`15.75rem`); ciut 64px (`4rem`), hanya ikon + mark logo |
| Area logo | tinggi 56px, padding 0 16px, garis bawah `--sidebar-border` |
| Label grup | 10px / 600, uppercase, `letter-spacing .5px`, padding 16px 12px 4px, tidak bisa diciutkan |
| Item | 13px / 400 (aktif 500), tinggi 32px, padding 6px 12px, radius 4px, ikon 18px opacity .5 (aktif/hover .85) |

### Topbar & footer

| Elemen | Nilai |
|---|---|
| Topbar | tinggi 56px, `rgba(255,255,255,.85)` + blur 12px (dark `rgba(20,29,43,.85)`), garis bawah `--border-color`, padding 0 24px (HP 12px) |
| Tombol ☰ | 34px, ikon 18px, `--text-secondary` |
| Pencarian global | lebar 300px, tinggi 36px, latar `--bg-surface-secondary`, radius 6px, 13px, hint `⌘K` (HP: lebar penuh, hint disembunyikan) |
| Menu akun | nama 14px + avatar 28px + caret 14px dalam pill radius 20px; nama disembunyikan < 600px |
| Footer | `gs.POS © tahun` kiri, `v{APP_VERSION}` kanan, 12px `--text-muted`, padding 16px 24px, garis atas `--border-color-light` |

Kedua panel memakai sidebar yang sama; `/admin` dibedakan oleh badge "Super Admin" di logo.

## 4. Tipografi

| Token | Nilai |
|---|---|
| Font UI | `Inter`, fallback `-apple-system, Segoe UI, Roboto, sans-serif` |
| Font brand | `Nunito` (hanya wordmark logo) |
| Font mono | `SF Mono, Monaco, Consolas, monospace` (nomor order, kode, SKU) |
| Angka | `font-variant-numeric: tabular-nums` untuk semua nominal & kuantitas |

| Skala | Ukuran / berat | Pemakaian |
|---|---|---|
| Page title | 18px / 600 | Judul halaman |
| Section title | 16px / 600 | Judul kartu/section |
| Body | 14px / 400 | Teks umum |
| Sel tabel | 13px / 400, `--text-secondary` | Isi tabel |
| Header tabel | 11px / 600, uppercase, `letter-spacing .3px`, `--text-muted`, latar `--bg-surface-secondary` | Judul kolom |
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
| Padding sel tabel | 12px × 12px (baris ±47px) |
| Padding kartu / section | 16px (header 12px × 16px, min. 44px) |
| Jarak antar field form | 16px |
| Jarak antar section | 24px |
| Padding halaman | 20px 24px 40px desktop, 12px mobile |

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
| `--radius-lg` | 8px | Kartu, section, tabel (border 1px `--border-color`) |
| `--radius` (badge) | 6px | Badge |
| `--shadow-card` | `0 0 0 1px rgba(4,32,69,.08), 0 2px 4px rgba(30,38,51,.04)` | Kartu |
| `--shadow-pop` | `0 8px 24px rgba(30,38,51,.14)` | Dropdown, date picker, modal |
| `--focus-ring` | `0 0 0 3px primary/15%, 0 0 0 1px primary` | Fokus keyboard semua kontrol |

## 8. Motion

Transisi `150ms ease` untuk warna, border, bayangan. Tidak ada animasi dekoratif.
Hormati `prefers-reduced-motion`.

## 9. Breakpoint

Mengikuti Tailwind/Filament: `sm 640` · `md 768` · `lg 1024` · `xl 1280`. Sidebar ciut di bawah `lg`.
