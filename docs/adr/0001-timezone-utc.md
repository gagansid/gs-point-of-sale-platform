# ADR 0001: Waktu disimpan dalam UTC, tampilan & nomor order memakai zona outlet

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q1, Q2

## Konteks

SPEC mewajibkan tanggal-waktu API dalam ISO 8601 UTC, tetapi contoh `.env` memakai
`APP_TIMEZONE=Asia/Jakarta`. Nomor order memakai `YYMMDD`; transaksi pukul 00:00–07:00 WIB jatuh
di tanggal UTC sebelumnya. Rencana fase 2 (multi-outlet) bisa melibatkan outlet di WITA/WIT.

## Pilihan

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| A. Server `Asia/Jakarta` | Tidak perlu konversi tampilan | Output API harus dikonversi manual; salah untuk outlet di luar WIB |
| B. Server UTC + zona per outlet | Konsisten dengan API; siap multi-zona | Perlu konversi di tampilan & penentuan "hari ini" |

## Keputusan

Kami memilih **B**:

- `APP_TIMEZONE=UTC`; semua kolom `timestamp` berisi UTC.
- Kolom baru `outlets.timezone` (string IANA, default `Asia/Jakarta`).
- **Tanggal lokal outlet** dipakai untuk: `YYMMDD` nomor order, `order_sequences.date`,
  filter `date`/`from`/`to` di API laporan, dan widget "hari ini".
- Panel Filament menampilkan waktu di zona outlet aktif.

## Konsekuensi

- Helper `TenantContext::outlet()->localDate()` / `->localNow()` dipakai di semua tempat yang butuh
  "hari ini"; dilarang memakai `today()` langsung untuk logika bisnis.
- Rentang tanggal laporan dikonversi ke rentang UTC sebelum query.
- Test wajib mencakup transaksi pukul 23:30 dan 00:30 WIB.
