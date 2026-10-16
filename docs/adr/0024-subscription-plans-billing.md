# ADR 0024: Paket langganan & tagihan SaaS

- Status: Diterima
- Tanggal: 2026-10-16 (revisi 2026-10-16: upgrade prorata, downgrade di akhir masa aktif)
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
| Jenis tagihan | `subscription_invoices.kind` (`App\Services\Billing\InvoiceQuote`, pratinjau sama di modal Buat tagihan). **Upgrade** (`upgrade`): langganan berbayar aktif (status aktif, belum habis), paket lebih mahal, siklus sama → nominal = (harga baru − harga lama) × sisa hari (dibulatkan ke atas) ÷ hari satu siklus, dibulatkan ke rupiah; jatuh tempo ≤ akhir masa aktif. **Perpanjangan** (`renewal`): harga penuh |
| Lunas | Upgrade: paket & `max_outlets` langsung diganti, masa aktif **tidak** bertambah (periode = sekarang–akhir masa aktif). Perpanjangan: `subscription_ends_at` = max(sekarang, akhir lama) + 1/12 bulan, status aktif; paket & siklus langsung diganti bila trial/habis/paket sama, selain itu (downgrade, ganti siklus selagi langganan berbayar berjalan) **dijadwalkan**: `tenants.next_plan_id`, `next_billing_cycle`, `plan_changes_at` = akhir masa aktif lama, diterapkan `pos:apply-plan-changes` tiap jam. Admin mengatur paket langsung = jadwal dibatalkan. Idempotent |
| Pengingat | `pos:billing-reminders` harian: email owner 7/3/1 hari sebelum berakhir (sekali per hari ambang), tagihan lewat jatuh tempo → kedaluwarsa |

## Konsekuensi

- Positif: perpanjangan mandiri, paket terstruktur, admin cukup memantau.
- Negatif: fitur yang dimatikan paket menyembunyikan menu, bukan mengunci data lama. Downgrade tanpa pengembalian dana:
  paket lama tetap dipakai sampai akhir masa aktif (paket terjadwal diterapkan paling lambat ±1 jam setelahnya). Ganti
  siklus ke paket lebih mahal tidak dihitung prorata (perpanjangan terjadwal); upgrade dulu di siklus yang sama bila
  ingin langsung.
