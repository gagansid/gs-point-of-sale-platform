# Halaman program member & self-order (lokal)

> Ringkasan halaman yang dibuat untuk program member (ADR 0013, M1–M7) dan self-order QR meja (ADR 0014, S1–S4),
> beserta cara masuknya. **Hanya untuk development lokal** (data `DemoTenantSeeder` + data uji 11 Okt 2026).
> Akses umum & skenario lain: [`TESTING-ACCESS.md`](TESTING-ACCESS.md). Jangan jalankan `migrate:fresh` / `db:wipe`.

Bisnis demo: **Kopi Senja (Demo)** · kode bisnis `kopi-senja-demo` · outlet **Kopi Senja Kemang** & **Kopi Senja Cilandak**.

## Akun login

| Untuk | Halaman masuk | Login | Kata sandi / PIN |
|---|---|---|---|
| Dashboard owner | http://gspos.localhost:8000/login | `owner@demo.test` | `password` |
| Dashboard manager | http://gspos.localhost:8000/login | `manager@demo.test` | `password` |
| Kasir web (kasir) | http://app.gspos.localhost:8000/cashier/login → tab **Username** | kode bisnis `kopi-senja-demo` · username `budi` | `password` (PIN `123456`) |
| Kasir web (supervisor) | idem | kode bisnis `kopi-senja-demo` · username `sari` | `password` (PIN `222222`) |
| Halaman member (pelanggan) | http://gspos.localhost:8000/m/kopi-senja-demo/login | nomor HP `0812-7777-1234` (Member Demo) | `demomember1` |
| Pesan dari meja (pelanggan) | http://gspos.localhost:8000/order/t/RD5Xk3V7FP0litLb8gaWrhLR1QqczJeg | tanpa login (nama wajib); member opsional lewat “Masuk sebagai member” | — |
| Panel super admin | http://admin.gspos.localhost:8000/login | `admin@demo.test` | `password` |

Catatan:
- Kasir web butuh **shift terbuka** di browser itu (diminta otomatis saat masuk); Budi bertugas di **Kopi Senja Kemang**.
- Member **Rina Kusuma** (`0812-3456-7890`) belum punya kata sandi halaman member; buat lewat dashboard → Member →
  Aksi lain → **Kata sandi sementara**.
- Token & URL pendek QR **Meja 5** berubah bila tombol **Ganti QR** dipakai; ambil URL baru dari **Cetak QR**.
- URL pendek meja: `http://gspos.localhost:8000/t/{kode}` (kode 6 karakter di kartu cetak) → dialihkan ke URL asli `/order/t/{token}`.
- Pembayaran online (Midtrans) butuh key **sandbox** milik owner di Pengaturan → Pembayaran online (key tidak
  dicatat di dokumen ini).

## Dashboard (http://app.gspos.localhost:8000) — login owner

| Menu | URL | Tahap | Isi | Izin |
|---|---|---|---|---|
| Pelanggan → Member | `/members` | M1–M7 | Daftar member (tier, poin, kunjungan), tambah, ubah, riwayat poin, sesuaikan poin, **kartu stempel**, kata sandi sementara, PIN member, nonaktif, hapus data, **Import / Export** Excel | `member.view` / `member.manage` |
| Pelanggan → Kartu stempel | `/stamp-programs` | M6 | Program “beli N gratis 1” per produk/kategori, aktif/nonaktif | `member.settings` (owner) |
| Pelanggan → Voucher | `/vouchers` | M7 | Tab **Kode promo** (tambah/ubah/nonaktif: nominal/persen, minimal belanja, kuota, periode, khusus member/tier/outlet) & **Ulang tahun** (dibuat otomatis) | `member.settings` |
| Pengaturan → Program member | `/settings/member-program` | M1–M7 | Aktifkan program, outlet ikut, aturan poin, diskon member, **tier** (Silver/Gold), **voucher ulang tahun** | `member.settings` |
| Pengaturan → Meja & QR | `/settings/tables` | S1 | Daftar meja per outlet, tambah/ubah, **Cetak QR**, **Ganti QR**, nonaktif; tombol **Setelan self-order** (aktif, batas waktu bayar, jam layanan, bayar di kasir, sambutan) | `table.manage` (owner, manager) |
| Cetak kartu QR | `/tables/print` (semua) · `/tables/print?ids={id}` | S1 | Kartu QR siap cetak dari browser | `table.manage` |
| Pengaturan → Pembayaran online | `/settings/online-payment` | S3 | Akun Midtrans owner (key terenkripsi), mode sandbox/production, metode online, URL notifikasi, **Tes koneksi** | `payment_gateway.manage` (owner) |
| Laporan → tab **Member** | `/reports?tab=members` | M7 | Member vs non-member, member aktif/berulang/baru, poin & utang poin, voucher, gratis stempel, member teratas, sebaran tier (ikut export) | `report.view` |
| Laporan → tab **Tipe order** | `/reports?tab=order_types` | S4 | Tambahan tabel **Per kanal** (kasir vs QR meja, dibayar online) | `report.view` |
| Penjualan | `/orders` | S1–S4 | Tab **Menunggu bayar**, filter **Kanal**, label “Perlu refund manual” | `order.view_all` |

## Kasir web (http://app.gspos.localhost:8000/cashier) — login Budi

| Halaman | URL | Tahap | Isi |
|---|---|---|---|
| Transaksi | `/cashier` | M1–M7, S2 | Cari/daftar member, chip **tier**, tombol **kartu stempel** & **voucher ulang tahun**, kolom **Kode voucher**, bayar poin; `?bill={order_id}` membuka pesanan meja untuk dibayar (item terkunci) |
| Pesanan meja | `/cashier/self-orders` | S2 | **Pesanan masuk** (terima/tolak + alasan) & **Belum dibayar** (Detail / Bayar); badge + bunyi di menu. Muncul bila self-order aktif di outlet |

## Halaman pelanggan (http://gspos.localhost:8000)

| Halaman | URL | Tahap | Isi |
|---|---|---|---|
| Masuk / daftar member | `/m/kopi-senja-demo/login` · `/register` | M4 | Nomor HP + kata sandi |
| Beranda member | `/m/kopi-senja-demo` | M4–M7 | Saldo poin, **progres tier**, **voucher pribadi**, **kartu stempel**, QR dinamis untuk kasir |
| Riwayat · Profil · Kata sandi | `/m/kopi-senja-demo/history` · `/profile` · `/password` | M4 | Riwayat poin & transaksi, ubah profil, ganti kata sandi, hapus akun |
| Pesan dari meja | `/order/t/{token}` | S2–S4 | Tab **Menu** (kategori, opsi), **Keranjang** (nama, catatan, voucher), **Pesananku** (status polling: menunggu kasir → silakan bayar → lunas / ditolak / kedaluwarsa), tombol **Bayar online** (Midtrans) & **Pakai poin** (member) |
| URL pendek meja | `/t/{kode}` | Q85 | Dialihkan ke `/order/t/{token}`; kode tercetak di kartu QR |
| Masuk member dari meja | `/order/t/{token}/member` | S2 | Ke login member, lalu kembali ke meja |

## API (http://api.gspos.localhost:8000/v1) — token kasir/owner

| Endpoint | Tahap | Dokumen |
|---|---|---|
| `GET /members/{id}` (+ `tier`, `stamp_cards`, `vouchers`) | M5–M7 | [`api/member.md`](api/member.md) |
| `GET /vouchers/check?code=` | M7 | [`api/member.md`](api/member.md) |
| `checkout` / `PUT /orders/{id}`: `stamp_program_ids`, `voucher_code` / `voucher_id` | M6–M7 | [`api/order.md`](api/order.md) |
| `GET /reports/members` · `GET /reports/channels` | M7, S4 | [`api/report.md`](api/report.md) |
| `GET /self-orders` · `POST /self-orders/{id}/accept` · `POST /self-orders/{id}/reject` | S4 | [`api/self-order.md`](api/self-order.md) |
| `GET /orders?status=awaiting_payment&channel=self_order` | S4 | [`api/self-order.md`](api/self-order.md) |

Webhook Midtrans (untuk dashboard Midtrans, bukan aplikasi):
`POST http://gspos.localhost:8000/webhooks/midtrans/01a125bd-526b-7071-8195-455675399e61` (tenant Kopi Senja Demo).

## Data demo yang sudah ada

| Data | Nilai |
|---|---|
| Tier | Silver (≥ Rp50.000/12 bln, poin ×2, diskon +5%) · Gold (≥ Rp200.000, poin ×3, diskon +10%) |
| Kartu stempel | “Kopi ke-10 gratis” (kategori Kopi, 10 stempel) |
| Kode promo | `HEMAT10` (10%) |
| Meja | Meja 5 — Kopi Senja Kemang, self-order aktif (bayar di kasir boleh) |

## Uji cepat alur pesan dari meja

1. Buka URL **Meja 5** di tampilan HP → pilih Croissant → Keranjang → isi nama → **Kirim pesanan**.
2. Kasir web (Budi) → **Pesanan meja** → **Terima**.
3. **Belum dibayar** → **Bayar** → Tunai → Selesaikan.
4. Kembali ke halaman HP → tab Pesananku menampilkan **Lunas**.
