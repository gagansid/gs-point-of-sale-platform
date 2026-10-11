# ADR 0014: Self-order lewat QR meja — terima kasir dulu, bayar dulu, Midtrans per owner

- Status: Diterima
- Tanggal: 2026-10-11
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q68–Q74, ADR 0013 (member), ADR 0011 (per outlet), ADR 0004 (shared hosting)

## Konteks

Di mode kafe, pelanggan ingin memesan dari meja lewat HP: scan QR, pilih menu, bayar non-tunai di HP atau tunai
di kasir. Kasir perlu melihat pesanan yang masuk dan bill yang belum dibayar. SPEC sebelumnya menaruh "tabel meja"
dan "integrasi payment gateway" di Fase 2. Hosting shared cPanel: tanpa WebSocket/proses berjalan terus.

## Pilihan

| Topik | Opsi | Dipilih |
|---|---|---|
| Kapan bayar | Bayar dulu · bayar nanti (open bill) · per outlet | **Bayar dulu** |
| Konfirmasi | Wajib terima kasir · otomatis · per outlet | **Wajib terima kasir** |
| Payment gateway | Akun owner (Midtrans/Xendit) · gs.POS sebagai platform · belum | **Akun Midtrans milik owner** |
| Menu kasir | Pesanan masuk · belum dibayar · keduanya | **Keduanya** |

Gabungan "bayar dulu" + "wajib terima kasir" disusun **terima dulu, baru bayar**: bila kasir menolak setelah
pelanggan membayar, dana harus dikembalikan, dan refund QRIS umumnya tidak bisa otomatis.

## Keputusan

### Alur & status order

| Status | Arti | Pemicu |
|---|---|---|
| `pending` | Pesanan dari meja, menunggu kasir | Pelanggan kirim dari HP |
| `awaiting_payment` | Diterima kasir, menunggu bayar | Kasir **terima** (cek ketersediaan); tolak → `rejected` + alasan |
| `completed` | Lunas → diproses dapur | Webhook Midtrans `settlement`/`capture` terverifikasi, atau kasir menerima tunai |
| `rejected` | Ditolak kasir | Kasir, dengan alasan (tampil di HP) |
| `expired` | Tidak dibayar dalam `payment_timeout_minutes` (bawaan 15) | Jadwal tiap menit; transaksi Midtrans dibatalkan |
| `voided` | Seperti order biasa | Void dengan PIN (Q18) |

- **Stok dipotong saat lunas**, bukan saat diterima: pesanan batal/kedaluwarsa tidak perlu mengembalikan stok.
  Ketersediaan dicek ulang saat terima & saat lunas; habis setelah lunas = tetap selesai, dapur/kasir menyesuaikan.
- Order meja masuk **shift kasir yang menerima** (`shift_id`, `device_id` perangkat itu); `channel = self_order`.
- Harga, pajak, service, diskon dihitung ulang server (`OrderCalculator`); nilai dari HP diabaikan. Snapshot harga
  diambil saat **diterima**; berubah setelah itu tidak memengaruhi tagihan.
- Member (ADR 0013): login di HP memasang member ke order; voucher dipilih di HP, divalidasi saat terima & saat lunas;
  poin/stempel diproses saat lunas. Poin bisa dipakai sebagai pembayaran dari HP (bukti = sesi member yang login).

### Meja & QR

| Hal | Keputusan |
|---|---|
| Data | `tables`: tenant_id, outlet_id, name ("Meja 5"), area, qr_token (acak 32 karakter), is_active, sort_order. Q-lama `orders.table_label` tetap, diisi nama meja |
| QR | `order.gspos.id/t/{qr_token}` (tanpa subdomain: `/order/t/{token}`); token bisa diganti (QR lama langsung mati); unduh/cetak PDF QR per meja atau semua meja dari dashboard |
| Setelan outlet | self_order_enabled, payment_timeout_minutes, jam layanan (opsional), metode online yang diizinkan, teks sambutan |

### Halaman pesan (HP)

- Web ringan (Blade + Livewire), tanpa instal; menu outlet sesuai katalog per outlet (ADR 0011): produk dijual & tersedia,
  opsi, gambar, catatan per item.
- Pesan sebagai **tamu** (nama wajib) atau **member** (login HP + kata sandi, ADR 0013).
- Status pesanan di HP (polling 5 detik): menunggu kasir → bayar (tombol Midtrans Snap untuk non-tunai, atau "bayar
  di kasir") → lunas (struk digital) / ditolak / kedaluwarsa. Pesanan susulan = order baru di meja yang sama.
- Batas: maks. 3 pesanan `pending` per meja, 10 pesanan/jam per IP, maks. 50 item per pesanan; kirim ganda
  idempotent lewat `id` dari klien.

### Kasir (web & tablet)

| Menu | Isi |
|---|---|
| **Pesanan masuk** | `pending` per meja (paling lama di atas): rincian, tombol **Terima** / **Tolak** (alasan), badge jumlah + suara; polling 5–10 detik |
| **Belum dibayar** | `awaiting_payment` (dari meja) + open bill kasir: meja, umur, total, status bayar online; terima tunai/metode lain seperti pelunasan open bill; batalkan |

### Midtrans per owner

| Hal | Keputusan |
|---|---|
| Akun | Tiap owner menghubungkan akun Midtrans sendiri di **Pengaturan → Pembayaran online** (`payment_gateway.manage`, owner): Server Key & Client Key disimpan **terenkripsi** (`encrypted` cast), tidak pernah ditampilkan lagi; mode sandbox/production; tombol "Tes koneksi" |
| Pembayaran | Snap (QRIS, e-wallet, VA sesuai yang diizinkan owner); `order_id` Midtrans = id pembayaran kita; nominal dari server |
| Konfirmasi | Webhook `POST /webhooks/midtrans/{tenant}`: verifikasi `signature_key` (SHA512 order_id+status_code+gross_amount+server_key), **cek ulang** status ke API Midtrans, cocokkan nominal, idempotent per transaksi; baru tandai lunas. Halaman HP hanya membaca status dari server |
| Metode bayar | Kategori baru `online` (dibuat otomatis saat Midtrans aktif): tidak masuk kas laci, tercatat per metode & dilaporkan terpisah; referensi = transaction_id Midtrans |
| Biaya | Biaya MDR ditanggung owner (di luar gs.POS); laporan menampilkan nominal bruto |
| Refund | Tidak otomatis. Void order online yang sudah lunas → tanda "perlu refund manual" di dashboard |

### Usulan izin & kode error (masuk tabel resmi SPEC saat diimplementasikan)

| Izin | Owner | Manager | Supervisor | Kasir |
|---|---|---|---|---|
| `self_order.handle` (terima/tolak, lihat pesanan masuk) | ✅ | ✅ | ✅ | ✅ |
| `table.manage` (meja & QR) | ✅ | ✅ | ❌ | ❌ |
| `payment_gateway.manage` | ✅ | ❌ | ❌ | ❌ |

Kode error: `SELF_ORDER_DISABLED` (422), `TABLE_NOT_FOUND` (404, QR tidak valid/diganti), `ORDER_NOT_PENDING` (409,
sudah diterima/ditolak kasir lain), `ORDER_EXPIRED` (409), `PAYMENT_GATEWAY_NOT_CONFIGURED` (422),
`PAYMENT_GATEWAY_ERROR` (502).

## Konsekuensi

- Positif: antrean kasir berkurang; pesanan iseng tersaring kasir; tanpa refund karena bayar setelah diterima; dana
  langsung ke owner tanpa gs.POS memegang uang.
- Negatif: notifikasi berbasis polling (beban request di shared hosting — interval & query harus ringan, indeks
  `orders(outlet_id, status)`); owner perlu akun & verifikasi Midtrans; status order bertambah sehingga laporan,
  open bill, dan API Flutter harus menyesuaikan.
- Netral: Flutter perlu menu Pesanan masuk & Belum dibayar (polling); dikerjakan di repo aplikasi.

## Tahapan (setelah member M1–M4)

| Tahap | Isi |
|---|---|
| S1 | Meja & QR (CRUD, cetak, ganti token), setelan self-order per outlet, status order baru |
| S2 | Halaman pesan HP (tamu/member), Pesanan masuk & Belum dibayar di kasir web, bayar tunai di kasir, kedaluwarsa |
| S3 | Midtrans per owner: setelan terenkripsi, Snap, webhook terverifikasi, metode bayar online |
| S4 | API Flutter (pesanan masuk, belum dibayar), laporan kanal self-order, integrasi poin sebagai pembayaran dari HP |
