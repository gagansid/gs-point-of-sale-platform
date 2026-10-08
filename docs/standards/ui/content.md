# Konten & Format

## 1. Bahasa

- Semua teks UI dalam **Bahasa Indonesia** baku yang ringkas. Istilah POS umum boleh tetap
  bahasa Inggris bila lebih dikenal kasir: *shift*, *void*, *open bill*, *QRIS*, *SKU*.
- Kalimat aktif, tanpa basa-basi. "Simpan", bukan "Klik di sini untuk menyimpan".
- Huruf kapital hanya di awal (sentence case): "Metode pembayaran", bukan "Metode Pembayaran"
  — **kecuali** nama menu di sidebar yang mengikuti SPEC.
- Tanpa titik di akhir label, tombol, judul, dan notifikasi satu kalimat.

## 2. Kosakata baku

Pakai istilah yang sama di API (`message`), panel, dan aplikasi Flutter.

| Pakai | Jangan |
|---|---|
| Tambah | Buat baru, Create, Add |
| Simpan | Submit, Kirim (untuk form) |
| Ubah | Edit, Update |
| Hapus | Delete, Buang |
| Batal (menutup modal) | Cancel, Kembali |
| Void / Batalkan transaksi | Hapus transaksi |
| Penjualan | Order (di judul menu) |
| Nomor order | Order ID, Invoice |
| Karyawan | User, Pengguna (di dashboard tenant) |
| Perangkat | Device |
| Kas awal / Kas aktual / Selisih | Opening cash, Actual cash, Difference |
| Stok habis | Out of stock |

## 3. Format data

| Data | Format | Contoh | Implementasi |
|---|---|---|---|
| Uang | `Rp` + titik ribuan, tanpa desimal bila ,00 | `Rp62.000`, `Rp1.250.500` | `->money('IDR', locale: 'id')` atau `MoneyColumn` |
| Uang negatif | Tanda minus sebelum `Rp`, warna danger | `-Rp5.000` | `MoneyColumn::signed()` |
| Persen | Koma desimal, tanpa nol berlebih | `11%`, `2,5%` | `->suffix('%')` + `numeric(locale: 'id')` |
| Kuantitas | Angka bulat, titik ribuan | `1.240` | `->numeric(locale: 'id')` |
| Tanggal | `d M Y` | `8 Okt 2026` | `->date('j M Y')` |
| Tanggal + jam | `d M Y, H.i` | `8 Okt 2026, 14.02` | `->dateTime('j M Y, H.i')` |
| Jam | `H.i` | `14.02` | `->time('H.i')` |
| Relatif | Untuk "terakhir aktif" | `5 menit lalu` | `->since()` |
| Nomor order | Mono, bisa disalin | `JKT01-261008-0042` | `->fontFamily('mono')->copyable()` |
| Kosong | Tanda pisah abu | `—` | `->placeholder('—')` |

- Waktu disimpan UTC; **tampilan selalu di zona outlet** (`outlets.timezone`, default `Asia/Jakarta`, ADR 0001).
  Gunakan `->timezone($outlet->timezone)` atau set default di panel provider.
- Locale Carbon & Filament: `id`.

## 4. Pesan

| Jenis | Pola | Contoh |
|---|---|---|
| Sukses | `{Objek} berhasil {aksi}` | "Produk berhasil disimpan" |
| Error validasi | Spesifik per field | "Harga wajib diisi" |
| Error bisnis | Kondisi + saran bila ada | "Shift belum dibuka. Buka shift dari aplikasi kasir" |
| Konfirmasi (judul) | Pertanyaan dengan nama objek | "Void order JKT01-261008-0042?" |
| Konfirmasi (isi) | Konsekuensi | "Stok dikembalikan dan transaksi tidak bisa dipulihkan" |
| Empty state | Apa + langkah berikutnya | "Belum ada produk" / "Tambahkan produk pertama untuk mulai berjualan" |

## 5. Label form

- Label singkat (1–3 kata); penjelasan masuk ke `->helperText()`.
- Wajib diisi ditandai otomatis oleh Filament (`*` merah); jangan menulis "(wajib)".
- Placeholder berisi contoh nilai, bukan pengganti label: `placeholder('Es Kopi Susu')`.
