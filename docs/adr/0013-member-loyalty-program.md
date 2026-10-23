# ADR 0013: Program member — milik bisnis, poin sebagai metode bayar, halaman member HP + kata sandi

- Status: Diterima
- Tanggal: 2026-10-11
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q60–Q67, ADR 0010 (multi-outlet), ADR 0011 (atur per outlet), ADR 0014 (self-order meja)

## Konteks

Kafe & UMKM ingin pelanggan kembali lagi: poin, diskon member, tingkatan (tier), kartu stempel, dan voucher.
Satu bisnis bisa punya beberapa outlet (ADR 0010) dengan owner yang sama, dan pelanggan sering berpindah cabang.
Belum ada data pelanggan sama sekali di sistem. Self-order lewat QR meja (ADR 0014) juga butuh identitas
pelanggan di HP.

## Pilihan cakupan

| Opsi | Cara kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| A. Per outlet | Member & poin terpisah per outlet | Sederhana | Pelanggan daftar ulang per cabang, poin terpecah |
| B. Per bisnis, semua outlet | Satu member & saldo di semua outlet | Pengalaman utuh, owner melihat pelanggan lintas cabang | Tidak bisa mengecualikan cabang (mis. franchise) |
| C. Per bisnis + outlet ikut/tidak | Seperti B, owner bisa mengecualikan outlet | Fleksibel | Satu setelan tambahan |

## Keputusan

Kami memilih **C**. Member milik **tenant**; tidak pernah lintas tenant (nomor HP yang sama di bisnis lain =
member lain, ADR 0005).

| Topik | Keputusan |
|---|---|
| Identitas | Nomor HP dinormalisasi ke `+62…`, **unik per tenant**; nama wajib; email, tanggal & bulan lahir (tanpa tahun) opsional. `code` member 6 karakter acak (bukan urut) untuk QR/kartu |
| Persetujuan (UU PDP) | Wajib saat daftar; `consent_at` disimpan. Hapus atas permintaan = data pribadi dianonimkan, transaksi & ledger tetap |
| Outlet ikut program | Tabel `outlet_member_program` (`is_enabled`); tanpa baris = ikut (pola ADR 0011). Outlet tidak ikut: member bisa dipasang (tercatat kunjungan) tetapi tanpa poin, stempel, diskon, voucher |
| Poin — kumpul | Dari **subtotal setelah diskon, sebelum pajak, service & pembulatan**; `floor(dasar / earn_amount) × earn_points × pengali tier`. Masuk saat order **selesai** (bukan open bill) |
| Poin — tukar | **Metode bayar kategori `points`** (dibuat otomatis per tenant, seperti 5 metode bawaan): 1 poin = `redeem_value` rupiah. Pajak dihitung dari harga penuh; tidak masuk kas laci, tanpa kembalian, dilaporkan terpisah. Minimal tukar & maksimal persen tagihan diatur owner |
| Bukti tukar | **QR dinamis** dari halaman member (berganti tiap 30 detik, berlaku 2 menit, sekali pakai) dipindai kasir; cadangan **PIN member 4 digit** (hash, kunci setelah 5 salah) atau persetujuan PIN supervisor (`VerifyApproval`) |
| Kedaluwarsa | Per mutasi kumpul, `expiry_months` (bawaan 12) — FIFO; dijalankan jadwal harian |
| Ledger | `member_point_ledger` hanya ditambah (`earn`, `redeem`, `void_reversal`, `adjust`, `expire`), `balance_after`, `remaining` untuk FIFO. `members.points_balance` = cache, diubah dalam transaksi yang sama dengan lock baris member (pola stok). Idempotent per `order_id`/`payment_id` |
| Void | Poin kumpul ditarik, poin tukar dikembalikan, stempel & voucher dikembalikan. Saldo boleh minus bila poin sudah terpakai (jujur, terlihat di laporan) |
| Diskon member | Persen tetap (bawaan 0 = mati), dicatat sebagai **diskon** level order baru `member_discount` di `OrderCalculator`, setelah diskon manual, sebelum service & pajak |
| Tier | Mis. Reguler / Silver / Gold: batas **total belanja 12 bulan bergulir** (order selesai, tanpa void), pengali poin & diskon tambahan per tier. Dievaluasi saat order selesai & jadwal harian (bisa turun). Owner mengatur nama & batas |
| Kartu stempel | Program per produk/kategori terpilih: 1 item = 1 stempel; `stamps_required` (mis. 10) = 1 item gratis (item **termurah** yang memenuhi, dicatat sebagai diskon item). Bisa beberapa program aktif. Stempel masuk saat order selesai |
| Voucher | **Ulang tahun**: dibuat otomatis awal bulan lahir (nominal/persen, berlaku sampai akhir bulan, sekali); maks. 1×/tahun, hanya member yang terdaftar sebelum bulan lahirnya, tanggal lahir terkunci setelah diisi member (revisi 2026-10-23, GSPOS-SEC-028). **Kode promo owner**: kode, nominal/persen, minimal belanja, kuota total & per member, periode, outlet, khusus member/tier. Dicatat sebagai diskon level order |
| Urutan diskon | Diskon item manual → gratis stempel → diskon order manual → voucher → diskon member/tier → service → pajak → pembulatan → pembayaran (termasuk Poin) |
| Halaman member | **`gspos.id/m/{kode-bisnis}`** (diputuskan saat M4: per bisnis karena HP unik per bisnis, tanpa subdomain baru), guard `member` terpisah. Login **nomor HP + kata sandi** (tanpa OTP/email). Isi: QR dinamis, saldo & riwayat poin, tier & progres, kartu stempel, voucher, riwayat transaksi, profil, ganti kata sandi, hapus akun |
| Lupa kata sandi | Tanpa reset mandiri. Kasir/owner (`member.manage`) membuat kata sandi sementara; wajib diganti saat login berikutnya |
| Keamanan login | Pesan umum (HP tidak terdaftar = kata sandi salah), waktu konstan, 5 percobaan/menit per HP+IP & 20/menit per IP, kunci 15 menit setelah 10 salah; sesi 30 hari, keluar semua sesi saat ganti kata sandi |
| Kasir | Cari member (HP/kode/nama, dibatasi 30/menit per perangkat, HP disamarkan `•••• 1234`), daftar cepat, pasang ke order/open bill, lihat saldo & perkiraan poin, tukar poin, pakai voucher. Struk: poin didapat, saldo, stempel |
| Laporan | Member vs non-member, member baru, kunjungan berulang, member teratas, poin keluar/ditukar/kedaluwarsa, **utang poin** (saldo aktif × `redeem_value`), pemakaian voucher & stempel |

### Data

| Tabel | Kolom utama |
|---|---|
| `member_settings` | tenant_id (unik), enabled, earn_amount, earn_points, redeem_value, min_redeem_points, max_redeem_percent, expiry_months, member_discount_percent, birthday_voucher (json) |
| `member_tiers` | tenant_id, name, min_spend_12m, point_multiplier, discount_percent, sort_order |
| `members` | tenant_id, code, name, phone, email, birth_day, birth_month, password, must_change_password, pin, pin_failed_attempts, pin_locked_until, tier_id, points_balance, spend_12m, total_spent, visit_count, last_visit_at, joined_outlet_id, consent_at, is_active, anonymized_at, created_by, updated_by, deleted_at |
| `member_point_ledger` | tenant_id, member_id, outlet_id, order_id, payment_id, type, points, remaining, balance_after, expires_at, reason, user_id |
| `outlet_member_program` | tenant_id, outlet_id, is_enabled |
| `stamp_programs` + `stamp_program_items` | tenant_id, name, stamps_required, is_active; product_id / category_id |
| `member_stamps` | tenant_id, member_id, stamp_program_id, order_id, change (+/−), balance_after |
| `vouchers` | tenant_id, code (unik per tenant, null untuk ulang tahun), type (amount/percent), value, max_discount, min_spend, quota, per_member_quota, starts_at, ends_at, member_only, tier_ids, outlet_ids, source (owner/birthday), member_id (voucher pribadi) |
| `voucher_redemptions` | tenant_id, voucher_id, member_id, order_id, amount, status (used/voided) |
| `orders` (+) | member_id, member_name (snapshot), member_tier (snapshot), member_discount, voucher_discount, points_earned, stamp_free_total |

### Usulan izin & kode error (masuk tabel resmi SPEC saat diimplementasikan)

| Izin | Owner | Manager | Supervisor | Kasir |
|---|---|---|---|---|
| `member.view` (cari, pasang ke order) | ✅ | ✅ | ✅ | ✅ |
| `member.create` (daftar cepat) | ✅ | ✅ | ✅ | ✅ |
| `member.manage` (ubah, nonaktif, hapus, reset kata sandi, export) | ✅ | ✅ | ❌ | ❌ |
| `member.points_adjust` | ✅ | ✅ | ❌ | ❌ |
| `member.settings` (program, tier, stempel, voucher) | ✅ | ❌ | ❌ | ❌ |

Kode error: `MEMBER_NOT_FOUND` (404), `MEMBER_PROGRAM_DISABLED` (422), `INSUFFICIENT_POINTS` (422),
`INVALID_MEMBER_PROOF` (422, QR/PIN member salah/kedaluwarsa), `MEMBER_PIN_LOCKED` (423), `VOUCHER_INVALID` (422,
details: alasan — kedaluwarsa, kuota habis, belum memenuhi minimal, bukan untuk member/outlet ini).

## Konsekuensi

- Positif: pelanggan dikenali di semua cabang; owner punya data loyalitas & utang poin yang bisa diaudit (ledger
  append-only, Riwayat perubahan ADR/Q58); penukaran poin terlindungi QR dinamis/PIN.
- Negatif: `OrderCalculator` bertambah 3 jenis potongan (stempel, voucher, member/tier) — wajib unit test urutan &
  pembulatan; poin sebagai metode bayar membuat pajak dihitung dari harga penuh (perlu dikonfirmasi ke konsultan pajak
  klien); tanpa OTP, keamanan akun member bergantung pada kata sandi & reset lewat kasir.
- Netral: aplikasi Flutter perlu layar cari/daftar member, tukar poin (scan QR), voucher; dikerjakan di repo aplikasi.

## Tahapan

| Tahap | Isi |
|---|---|
| M1 | Tabel inti, `member_settings`, outlet ikut/tidak, menu Member (dashboard), cari & daftar cepat (kasir web + API), pasang member ke order |
| M2 | Ledger poin: kumpul saat selesai, void, kedaluwarsa (jadwal), penyesuaian manual, struk, API |
| M3 | Metode bayar Poin + bukti QR dinamis/PIN, diskon member di `OrderCalculator` |
| M4 | Halaman member (login HP + kata sandi, QR, saldo, riwayat, profil, hapus akun), reset kata sandi oleh kasir/owner |
| M5 | Tier (evaluasi saat selesai & harian, pengali poin, diskon) |
| M6 | Kartu stempel |
| M7 | Voucher ulang tahun & kode promo, laporan member, ringkasan harian, import/export |
