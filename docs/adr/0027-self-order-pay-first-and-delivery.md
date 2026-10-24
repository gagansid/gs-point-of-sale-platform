# ADR 0027: Pesan dari meja — bayar dulu tanpa terima kasir, QRIS di halaman, login member, antrean sampai diantar

- Status: Diterima
- Tanggal: 2026-10-23
- Pengambil keputusan: Gagan Suganda (K1–K9, 2026-10-23)
- Terkait: SPEC Q137 (keputusan ini), Q68–Q78 (self-order, sebagian diubah), Q66 (voucher), Q128 (tampilan bisnis),
  Q129 (opsi bawaan), Q136 (GSPOS-SEC-027), ADR 0013 (member), ADR 0014 (diubah sebagian), ADR 0017 (shift),
  ADR 0026 (hak akses)

## Konteks

Alur ADR 0014 menyusun pesanan meja sebagai *kirim → kasir terima → bayar → lunas*. Di lapangan:

- pelanggan menunggu kasir menekan **Terima** sebelum bisa membayar, padahal kasir sedang melayani antrean;
- bayar online membuka halaman Midtrans Snap (keluar dari halaman meja) tanpa layar "menunggu pembayaran";
- status berhenti di **Lunas**: tidak ada tahap disiapkan/siap/diantar dan tidak ada layar antrean untuk staf;
- halaman meja & member memakai warna navy statis dan ukuran kontrol sendiri, tidak mengikuti tema bisnis (Q128).

## Pilihan

| Topik | Opsi | Dipilih |
|---|---|---|
| Identitas saat bayar | Tamu cukup nama · **login member wajib sebelum bayar** · per outlet | **Login wajib (K1)** |
| Konfirmasi kasir | Tetap terima dulu · **bayar dulu, langsung antre** | **Bayar dulu (K2)** |
| QRIS | Snap (redirect) · **Core API `qris`, QR tampil di halaman meja** | **Core API (K3)**; Snap hanya e-wallet/VA |
| Layar staf | Hanya menu kasir · **halaman antrean tersendiri + dashboard** | **`/cashier/queue` + dashboard Antrean pesanan (K4, K8)** |
| Pesanan lupa ditandai | Dibiarkan · **selesai otomatis** | **Otomatis 60 menit sejak lunas, setelan outlet 30–240 (K4)** |
| Daftar member di meja | Arahkan ke halaman member · **daftar singkat dari meja** | **Daftar singkat tanpa OTP (K5)** |
| Tampilan | Gaya situs · **tema bisnis + ukuran panel owner** | **Tema bisnis (K6), semua kontrol 32px (K7)** |
| Izin | Pakai `self_order.handle` · **izin baru `order.fulfill`** | **`order.fulfill`, bawaan semua role (K9)** |

## Keputusan

### 1. Alur pelanggan

```
Menu → (opsi) → Keranjang → Bayar → [belum login → Masuk/Daftar → kembali, keranjang utuh]
     → pilih metode → QRIS di halaman / Tunjukkan ke kasir / Poin → Lunas → Antre → Disiapkan → Siap diantar → Diantar
```

- Memilih menu & mengisi keranjang **boleh tanpa login**. Login member wajib sebelum **semua** metode bayar (K1).
  Tombol **Masuk** di header tersedia kapan saja.
- Keranjang (item, catatan, kode voucher) disimpan di **session per meja** (`self_order_cart.{table_id}`, maks. 50 item),
  bukan di URL; kembali dari login/daftar lewat `member.return_to` dengan keranjang utuh.
- **Daftar singkat** dari meja (K5): nama, No. HP, kata sandi, persetujuan data; memakai `RegisterMemberAccount` dan
  throttle daftar yang sudah ada; tanpa OTP.
- Halaman meja punya **Masuk** (`/order/t/{token}/login`) & **Daftar** (`/order/t/{token}/register`) sendiri, bertema bisnis,
  field berikon + tombol tampilkan kata sandi. Logika & batas percobaan dipindah ke Action `AuthenticateMember` /
  `SignUpMember`, dipakai juga halaman member `/m/{bisnis}`. Kata sandi sementara dari kasir → ganti dulu di halaman
  member lalu kembali ke meja. Keluar dari menu akun header (`/order/t/{token}/logout`), keranjang tetap.
- **Program member nonaktif** (bisnis/paket): login tidak mungkin, jadi pelanggan tetap memesan sebagai **tamu dengan
  nama** (perilaku ADR 0014). Login wajib berlaku bila program member aktif (`SelfOrderContext::loginRequired()`),
  ditegakkan di Action (`UNAUTHENTICATED`), bukan hanya di tampilan.
- Keranjang dipulihkan dari session dan **divalidasi ulang** terhadap katalog outlet (produk/opsi yang tidak ada dibuang,
  nama dari katalog, maks. 50 item). Properti keranjang Livewire `#[Locked]`.
- Pesanan susulan di meja yang sama tetap bisa dibuat; layar status menampilkan semua pesanan sesi ini.

### 2. Bayar dulu tanpa terima kasir (K2)

| Langkah | Keputusan |
|---|---|
| Checkout | Action `CheckoutSelfOrder` membuat **order `awaiting_payment` langsung** dari keranjang: item, opsi, ketersediaan, harga, member & voucher dihitung ulang server (`OrderCalculator` lewat `QuoteOrder`/`PriceOrder`) tepat sebelum tagihan dibuat; snapshot nama & harga diambil saat itu. Baris `self_orders` tetap dibuat (status `accepted`, `order_id` terisi, `handled_by` kosong) sebagai catatan sesi HP & riwayat dashboard |
| Shift & kasir | `orders.shift_id`/`user_id` wajib (NOT NULL). Order masuk **shift terbuka paling awal di outlet itu**; `user_id` = operator aktif shift itu (ADR 0017, `shift_operators`), cadangan pembuka shift; `device_id` = perangkat shift. **Tidak ada shift terbuka** = outlet belum beroperasi → checkout ditolak `SELF_ORDER_DISABLED` ("Kasir belum buka. Silakan pesan di kasir.") dan halaman menu menampilkan pemberitahuan yang sama |
| Lunas | Webhook Midtrans terverifikasi, kasir menerima pembayaran di layar Transaksi (Belum dibayar), atau poin penuh. Saat order kanal `self_order` selesai, `OrderWriter::complete()` mengisi `fulfillment_status = queued` — **satu titik** untuk semua metode. Tagihan 0 (voucher penuh) langsung lunas & antre |
| Waktu lunas | `orders.completed_at` (sudah ada, = saat lunas). Tidak menambah `paid_at` |
| Stok | Dipotong saat lunas (tetap seperti ADR 0014) |
| Batas | Tetap: maks. **3 pesanan menunggu bayar per meja**, 10 pesanan/jam per IP, 50 item, `VoucherAttempts` (GSPOS-SEC-027), throttle URL pendek & login/daftar member. `id` order dari klien = idempotency key |
| Kedaluwarsa | Tidak dibayar dalam `payment_timeout_minutes` sejak checkout → `expired` oleh `pos:expire-self-orders`; QRIS/Snap yang belum dibayar dibatalkan. Dibayar setelah kedaluwarsa tetap diselesaikan (aturan Q77) |
| Pesanan lama | `self_orders.pending` dari alur lama masih bisa **Terima/Tolak** di kasir & API sampai habis; pesanan baru tidak pernah `pending` |

### 3. QRIS di halaman (K3)

| Hal | Keputusan |
|---|---|
| Pembuatan | Action `StartTableQrisPayment` → `MidtransClient::chargeQris()` (Core API `POST /v2/charge`, `payment_type=qris`). `order_id` Midtrans = id `gateway_transactions`; nominal = sisa tagihan server dibulatkan ke atas ke rupiah |
| Penyimpanan | `gateway_transactions.method` (`snap`/`qris`), `qr_string`, `expires_at` (dari `expiry_time` Midtrans, maks. 15 menit) |
| Satu QR aktif | QR pending yang masih berlaku dipakai ulang (klik ganda = QR sama); **Buat QR baru** membatalkan QR lama di Midtrans lalu membuat yang baru |
| Tampilan | QR dirender server (`App\Support\QrSvg`) dari `qr_string` di kartu putih (tetap putih di mode gelap), hitung mundur, instruksi 3 langkah, tombol Simpan QR; status **hanya dibaca** dari DB saat pelanggan kembali ke tab atau menekan Cek status pembayaran (tanpa polling, Q138) |
| Lunas | Hanya lewat webhook terverifikasi (tanda tangan → transaksi milik tenant → cek ulang API → nominal cocok). **Webhook tidak diubah**. Halaman HP tidak bisa menandai lunas |
| Snap | Tetap dipakai hanya untuk e-wallet/VA bila diizinkan owner (`enabled_payments` selain `qris`) |
| Batas | Membuat tagihan QRIS: maks. 5 per 10 menit per order dan 10 per 10 menit per IP → `TOO_MANY_REQUESTS` |
| Metode tersedia | QRIS = Midtrans siap + fitur `online_payment` + `qris` diizinkan owner + setelan outlet `allow_qris`; Bayar di kasir = `allow_pay_at_cashier`; Poin = `allow_points` + poin cukup (`PayWithPointsFromPhone`). Metode tidak tersedia disembunyikan |

### 4. Pengantaran (K4, K8, K9)

| Tahap (`orders.fulfillment_status`) | Pemicu | Waktu |
|---|---|---|
| `queued` (Antre) | Lunas | `completed_at` |
| `preparing` (Disiapkan) | Staf | `prepared_at` |
| `ready` (Siap diantar) | Staf | `ready_at` |
| `served` (Diantar) | Staf menandai sudah diantar ke meja | `served_at`, `served_by` |
| `auto_completed` (Selesai otomatis) | Jadwal: lunas & belum `served` setelah `auto_complete_minutes` (bawaan 60, rentang 30–240, per outlet) | `served_at`, `fulfillment_auto = true` |

- Kolom nullable: order non-meja tetap `NULL`. Enum `App\Enums\FulfillmentStatus` (label & warna).
- Action `AdvanceOrderFulfillment`: hanya maju (boleh melompat ke depan, mis. Antre → Diantar), tidak pernah mundur;
  meminta tahap yang sudah tercapai = **idempotent** (tanpa perubahan). Order bukan lunas/void → `ORDER_ALREADY_CLOSED`.
  **Urungkan 5 detik** = `UndoOrderFulfillment` mengembalikan tahap sebelumnya hanya untuk perubahan oleh user yang
  sama dalam 10 detik terakhir.
- Action `AutoCompleteFulfillment` + command `pos:auto-complete-fulfillment` tiap 5 menit (`schedule:run`): aman
  diulang, tercatat di log aktivitas (`log_name = fulfillment`, event `auto_completed`), tidak menyentuh order belum
  lunas/void/sudah diantar.
- Kolom tambahan untuk Urungkan: `fulfillment_previous`, `fulfillment_updated_by`, `fulfillment_updated_at`. API
  menampilkan `paid_at` (= `completed_at` pesanan meja). Badge & bunyi kasir web pindah ke menu **Antrean** (pesanan Antre
  + pesanan lama `pending`).
- **Batalkan pesanan lunas** (menu habis): memakai `VoidOrder` yang sudah ada — tidak ada Action baru — lewat ⋯ di kartu
  antrean → detail order (kasir web) / aksi Void (dashboard, API): alasan wajib, PIN approval sesuai `order.void` (Q18);
  order dibayar online → `refund_required` (refund manual di dashboard Midtrans, Q73).
  Batal sebagian item memakai retur sebagian (ADR 0018) dari detail order. Pelanggan melihat status *Dibatalkan*.
- Izin baru **`order.fulfill`** (memajukan tahap, melihat antrean): bawaan **semua role**, bisa dicabut owner per role
  atau per karyawan (ADR 0026). `self_order.handle` tetap untuk Terima/Tolak pesanan lama.
- **`/cashier/queue`** (kasir web, tablet dapur/bar) = halaman operasional utama: kolom Antre/Disiapkan · Siap diantar ·
  Diantar (1 jam terakhir), polling 5 detik, bunyi pesanan lunas baru, satu tombol langkah berikutnya, ⋯ Batalkan.
  Hanya outlet kasir web yang aktif (penugasan outlet berlaku).
- **Dashboard → Transaksi → Antrean pesanan** (`DashboardPage`, `canUsePage()` = `order.fulfill`, fitur `self_order`):
  statistik hari ini, tab cepat, tabel standar, aksi langkah, polling 10 detik, tautan ke `/cashier/queue`.
- Menu kasir **Pesanan meja** sementara memakai tombol langkah yang sama (Action yang sama).

### 5. Setelan outlet (Pengaturan pesan dari meja)

Kolom baru `self_order_settings`: `allow_qris` (bawaan ya), `allow_points` (bawaan ya),
`auto_complete_minutes` (bawaan 60, 30–240). Tetap: `allow_pay_at_cashier`, `payment_timeout_minutes`, jam layanan,
teks sambutan.

### 6. Tampilan pelanggan (K6, K7)

- Halaman meja **dan** halaman member `/m/{bisnis}` memakai token panel owner (`tokens.css`) + `TenantAppearance::css()`,
  `<meta name="theme-color">` = warna bisnis, mode terang/gelap/ikuti perangkat dari `TenantAppearance::themeMode()`
  (tanpa toggle di halaman pelanggan), logo bisnis (`TenantAppearance::logo()`, cadangan inisial).
- Entri CSS terpisah `resources/css/self-order.css` (kelas `.so-*`) agar token `:root` panel tidak bocor ke halaman situs
  & kasir web. Tanpa hex hard-coded selain putih kartu QR.
- Semua kontrol **32px** (`--control-h`), font kontrol 13px, label 12.5px, radius 6px; area sentuh diperluas dengan
  pseudo-element transparan tanpa mengubah tampilan. Kelas `.self-order-*` lama dihapus.
- Versi gs.POS tidak lagi di kaki halaman: menu akun header → **Tentang aplikasi** (dialog). Keranjang ringkas: baris
  diketuk = ubah, stepper kecil; catatan & voucher berupa baris yang bisa dibuka (masukan pemilik 2026-10-23).
- **Tampilan menu dua mode** (permintaan pemilik 2026-10-23):
  - **Kotak (grid):** di HP minimal **3 kartu per baris** (360–430px), tablet lebih banyak; gambar 1:1 radius 8px,
    nama maks. 2 baris, harga `tabular-nums`, tombol `+`; semua kartu sama tinggi.
  - **Baris (list):** satu produk per baris, gambar kecil seragam (56px, 1:1) di kiri, nama, deskripsi 1 baris, harga,
    tombol `+`/stepper di kanan.
  - Produk tanpa gambar tetap rapi: kotak placeholder berukuran sama (latar `--primary-lt`, inisial/ikon), bukan ruang kosong.
  - Pilihan mode disimpan di **`localStorage`** perangkat (`gspos.so.view`), tanpa request ke server; bawaan Kotak.
- **Navigasi bawah** (permintaan pemilik 2026-10-23, standar aplikasi HP): tab **Menu · Keranjang · Pesananku**
  selalu di bilah bawah tetap (bukan di atas), ikon + label, badge jumlah item/pesanan, aman
  `env(safe-area-inset-bottom)`, sama dengan navigasi bawah halaman member. Bila keranjang berisi, ringkasan
  `2 item · Rp54.000` + tombol utama 32px menempel tepat di atas bilah navigasi. Konten tidak tertutup keduanya.
- **Tab Profil** (permintaan pemilik 2026-10-23) di samping Pesananku: kartu profil (nama, No. HP disamarkan, tier, poin
  & nilainya) dan daftar menu: Riwayat pesanan (di halaman meja), Kartu member & QR, Riwayat poin, Ubah profil, Ganti kata
  sandi, Tentang aplikasi, Keluar. Tamu: ajakan Masuk/Daftar + Tentang aplikasi. Halaman member yang dibuka dari sini
  menampilkan tautan **Kembali ke meja**.

### 7. API Flutter

- Resource order menambah `fulfillment_status`, `prepared_at`, `ready_at`, `served_at`, `fulfillment_auto`.
- `POST /orders/{id}/fulfillment` `{ "status": "preparing" | "ready" | "served" }` (izin `order.fulfill`), idempotent.
- `GET /orders?fulfillment=queued,preparing,ready` untuk layar antrean aplikasi. Dicatat di `docs/api/flutter-changes.md`.

### 8. Kode error

Tidak ada kode baru: `SELF_ORDER_DISABLED`, `TOO_MANY_REQUESTS`, `VOUCHER_INVALID`, `PAYMENT_GATEWAY_NOT_CONFIGURED`,
`PAYMENT_GATEWAY_ERROR`, `ORDER_EXPIRED`, `ORDER_ALREADY_CLOSED`, `UNAUTHENTICATED` (bayar tanpa sesi member),
`FORBIDDEN`, `NOT_FOUND`.

## Konsekuensi

- Positif: pelanggan tidak menunggu kasir untuk membayar; QRIS tidak keluar dari halaman; staf punya satu layar untuk
  memajukan pesanan; owner bisa memantau waktu lunas → diantar; tampilan pelanggan mengikuti merek bisnis.
- Negatif: risiko **menu habis setelah lunas** (bayar dulu) → batal + refund manual; checkout butuh shift terbuka di
  outlet; polling bertambah (antrean 5 detik, dashboard 10 detik; HP tanpa polling sejak Q138) — query ringan
  dengan indeks `orders(outlet_id, fulfillment_status)`.
- Netral: aplikasi Flutter perlu layar antrean & tombol langkah (F4); Terima/Tolak tetap ada untuk pesanan lama.

## Tahapan

| Tahap | Isi |
|---|---|
| T1 | Fondasi tema: layout meja & member bertema bisnis, kelas `.so-*` 32px |
| T2 | Menu, pencarian, kategori scroll-spy, dialog opsi, keranjang |
| T3 | Login/daftar wajib sebelum bayar + keranjang selamat |
| T4 | Bayar dulu: checkout server, QRIS di halaman, bayar di kasir, poin; tanpa terima kasir |
| T5 | Pengantaran: migrasi, enum, Action, izin, `/cashier/queue`, kasir Pesanan meja, lini waktu HP, selesai otomatis, API |
| T5b | Dashboard → Transaksi → Antrean pesanan |
| T6 | Keadaan khusus, aksesibilitas, mode gelap, uji lintas viewport & tema, dokumentasi |
