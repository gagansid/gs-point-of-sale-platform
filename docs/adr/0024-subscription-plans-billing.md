# ADR 0024: Paket langganan & tagihan SaaS

- Status: Diterima
- Tanggal: 2026-10-16
- Pengambil keputusan: Gagan Suganda (keputusan bawaan diusulkan Claude, disetujui lewat "lanjut hingga selesai")
- Terkait: SPEC Q108–Q111, ADR 0009 (trial, hanya-baca, edisi), ADR 0010 (`max_outlets`), ADR 0014 (Midtrans owner)

## Konteks

Edisi SaaS punya trial, status akses (`full`/`read_only`/`blocked`), dan `max_outlets` yang diatur admin manual.
Belum ada paket, daftar fitur per paket, tagihan, maupun pembayaran langganan, sehingga perpanjangan dilakukan admin
satu per satu.

## Keputusan

| Topik | Keputusan |
|---|---|
| Paket | `plans` dikelola super admin: kode, nama, harga bulanan & tahunan, maks. outlet, **fitur** (`member`, `self_order`, `stock`, `recipes`, `promotions`, `credit`), aktif/publik |
| Tenant | `tenants.plan_id` + `billing_cycle`; **tanpa paket = semua fitur** (tenant lama & yang diatur manual); edisi jual putus selalu semua fitur & tanpa tagihan |
| Fitur | `Features::enabled('promotions')` menyembunyikan menu dashboard terkait & mematikan mesin (promo otomatis, kasbon, self-order, menu stok lanjutan, resep/paket, program member). Data lama tidak dihapus |
| Tagihan | Owner (Pengaturan → **Langganan**) memilih paket & siklus → `subscription_invoices` (nomor `INV-{YYMM}-{urut}`, nominal snapshot, jatuh tempo 3 hari). Satu tagihan menunggu per tenant (dibuat ulang = mengganti yang lama) |
| Bayar | **Midtrans vendor** (`POS_BILLING_MIDTRANS_SERVER_KEY`, Snap) + webhook `POST /webhooks/billing/midtrans` (tanda tangan + cek status API + nominal); tanpa key = transfer manual (instruksi `POS_BILLING_BANK_INFO`), admin **Tandai lunas** |
| Lunas | Paket & siklus tenant diganti, `max_outlets` = paket, status aktif, `subscription_ends_at` = max(sekarang, akhir lama) + 1/12 bulan; idempotent |
| Pengingat | `pos:billing-reminders` harian: email owner 7/3/1 hari sebelum berakhir (sekali per hari ambang), tagihan lewat jatuh tempo → kedaluwarsa |

## Konsekuensi

- Positif: perpanjangan mandiri, paket terstruktur, admin cukup memantau.
- Negatif: tanpa prorata saat ganti paket (paket baru berlaku saat tagihan lunas, masa aktif ditambah penuh); fitur yang
  dimatikan paket menyembunyikan menu, bukan mengunci data lama.
