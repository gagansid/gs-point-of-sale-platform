# ADR 0009: Onboarding pelanggan (daftar sendiri + dibantu sales) dan dua edisi (SaaS & jual putus)

- Status: Diterima
- Tanggal: 2026-10-10
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q30–Q34, ADR 0008

## Konteks

Pembeli utama adalah UMKM dengan **satu outlet** (warung, kafe). Saat ini bisnis baru hanya bisa
dibuat oleh super admin di `admin.gspos.id` (`CreateTenantWithOwner`), lalu kata sandi owner
dikirim manual. Untuk banyak pelanggan kecil ini lambat dan bergantung pada tim.

Produk akan dijual dengan dua cara: **langganan (SaaS)** di server kami, dan **jual putus**
(dipasang di server pembeli, pembeli juga memegang panel admin).

Panel admin tetap ruang kerja **pengelola sistem**, bukan pembeli SaaS (ADR 0008).

## Pilihan

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| A. Daftar sendiri + trial | Cepat, tanpa menunggu tim | Rawan akun palsu; pembeli setup sendiri |
| B. Dibantu sales saja | Data rapi, pendampingan | Lambat, tidak skala |
| C. Gabungan A + B | Fleksibel: mencoba sendiri atau didampingi | Dua alur untuk dirawat |

## Keputusan

Kami memilih **C** dan dua **edisi** dalam satu kode.

**Onboarding (edisi SaaS):**

1. **Daftar sendiri** di `gspos.id/register` (bisa dimatikan dari admin): membuat tenant (status
   `trial`), outlet, owner, metode bayar bawaan lewat `CreateTenantWithOwner`, lalu langsung masuk
   `app.gspos.id` lewat tiket login (ADR 0008).
2. **Lama trial diatur dari admin** (`system_settings.trial_days`, default **14**). Trial =
   `status = trial` + `subscription_ends_at = sekarang + trial_days`.
3. **Verifikasi email menyusul:** owner langsung bisa setup menu; banner "Verifikasi email" tampil;
   **checkout & pembayaran ditolak `EMAIL_NOT_VERIFIED`** sampai email owner terverifikasi
   (`users.email_verified_at`, link bertanda tangan).
4. **Dibantu sales:** di menu Calon pelanggan ada aksi "Buat tenant dari lead" (data terisi);
   owner menerima **link atur kata sandi**, bukan kata sandi teks.
5. **Panduan setup** di beranda `app.` (profil outlet → kategori → produk → PIN kasir → tablet) +
   template menu siap pakai (Kafe, Warung).

**Akses tenant (berlaku kedua edisi):**

| Tingkat | Kapan | Perilaku |
|---|---|---|
| Penuh | `active`/`trial` dan langganan belum habis | Normal |
| Hanya-baca | Trial/langganan habis (`subscription_ends_at` lewat), belum ditangguhkan | Login & lihat data/laporan boleh; semua perubahan & transaksi ditolak `SUBSCRIPTION_EXPIRED`; banner "hubungi sales" |
| Diblokir | `status = suspended` | Seperti sekarang: `TENANT_SUSPENDED`, tidak bisa login |

**Edisi** lewat env `POS_EDITION`:

| | `saas` (default) | `self_hosted` (jual putus) |
|---|---|---|
| Tenant | Banyak | **Tepat satu**, dibuat `php artisan pos:install` |
| Halaman depan & daftar | Aktif | Mati (`/` → login) |
| Panel admin | Tim kami | Pembeli (maintenance, 2FA) — tanpa menu calon pelanggan |
| Trial/langganan | Ya | Tidak (`subscription_ends_at = null`) |
| Subdomain | Disarankan (ADR 0008) | Opsional (bisa satu domain) |

Lisensi/kunci aktivasi untuk jual putus **di luar MVP** — diatur kontrak.

## Konsekuensi

- Positif: pelanggan kecil bisa mencoba hari itu juga; tim fokus ke yang butuh pendampingan;
  satu kode untuk dua model bisnis.
- Negatif / risiko: akun palsu (dimitigasi: rate limit, honeypot, verifikasi email sebelum transaksi);
  `isActive()` lama perlu dipecah menjadi tingkat akses; edisi `self_hosted` perlu diuji terpisah.
- Yang perlu diubah: `system_settings` (trial_days, signup_enabled), `users.email_verified_at`,
  `Tenant` (tingkat akses), middleware API & dashboard, `CreateTenantWithOwner` (trial), error code
  `SUBSCRIPTION_EXPIRED` & `EMAIL_NOT_VERIFIED`, `config/pos.php` (`edition`), command `pos:install`.
- Urutan kerja: `docs/PROGRESS.md` → "Onboarding & edisi".
