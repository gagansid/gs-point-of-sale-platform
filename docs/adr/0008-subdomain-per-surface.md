# ADR 0008: Subdomain terpisah untuk halaman depan, panel pelanggan, panel admin, dan API

- Status: Diterima
- Tanggal: 2026-10-10
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q28, ADR 0005, ADR 0006

## Konteks

Sebelumnya semua bagian ada di satu domain dengan path: `/dashboard` (owner), `/admin` (super admin),
`/api/v1` (Flutter), dan `/` kosong. URL panel pelanggan jadi panjang (`/dashboard/products`),
halaman depan belum ada, dan halaman login admin berada di domain yang sama dengan pelanggan.

Panel pelanggan dan admin **tetap dipisah** (bukan digabung dengan permission): pengguna, tabel akun,
guard, dan lingkup data berbeda (satu tenant vs lintas tenant). Menggabungkan berarti batas antar
tenant tinggal satu pengecekan permission — satu kelalaian membocorkan data semua pelanggan.

## Pilihan

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| A. Satu domain, path diganti (`/app`, `/ops`) | Paling sederhana | URL tetap ber-prefix; cookie admin & pelanggan sama domain |
| B. Subdomain per bagian | URL bersih (`app.gspos.id/products`); cookie session terpisah per subdomain; API bisa dipindah/di-rate-limit sendiri | Perlu 3 subdomain + SSL di cPanel |
| C. Satu login untuk semua lalu dialihkan | Satu pintu masuk | Login admin terbuka di halaman publik pelanggan |

## Keputusan

Kami memilih **B**:

| Domain | Isi |
|---|---|
| `gspos.id` | Halaman depan (penjualan), form hubungi sales, **login pelanggan** (`/login`) |
| `app.gspos.id` | Panel Filament `dashboard` di root (`/products`, `/orders`, ...) |
| `admin.gspos.id` | Panel Filament `admin` di root |
| `api.gspos.id/v1` | REST API |

Diatur lewat env (`POS_APP_DOMAIN`, `POS_ADMIN_DOMAIN`, `POS_API_DOMAIN`, opsional `POS_MAIN_DOMAIN`)
dan `App\Support\Domains`. Bila `POS_APP_DOMAIN` kosong, semua kembali ke satu domain dengan path lama
— dipakai test (`phpunit.xml`) dan hosting tanpa subdomain. Lokal memakai `*.gspos.localhost`
(otomatis ke 127.0.0.1).

## Konsekuensi

- Positif: URL panel tanpa `/dashboard`; cookie session admin & pelanggan terpisah (`SESSION_DOMAIN`
  kosong = host-only); halaman login admin tidak lagi di domain publik pelanggan.
- URL lama di domain utama dialihkan: `/dashboard/*` dan `/admin/*` → 301, `/api/v1/*` → 308
  (metode & body tetap). Route pengalih tanpa CSRF karena tidak membaca/mengubah data.
- Disk `public` memakai URL relatif `/storage` (semua subdomain berbagi folder `public`) agar pratinjau
  gambar di `app.` tidak terblokir CORS; `image_url` di API tetap absolut lewat `url()`.
- Aplikasi Flutter: base URL `https://api.gspos.id`, path `/v1/...`.
- **Login pelanggan di `gspos.id/login`** (bukan `app.`): karena cookie host-only, domain utama hanya
  memverifikasi kata sandi lalu menerbitkan **tiket sekali pakai** (64 karakter acak, disimpan sebagai
  hash di cache, 60 detik) dan mengalihkan ke `app.gspos.id/auth/ticket`, yang menukarnya menjadi sesi
  (`session()->regenerate()`). `app.gspos.id/login` & logout kembali ke `gspos.id/login` membawa path
  tujuan (`next`, hanya path relatif — anti open redirect). Login super admin **tetap** di
  `admin.gspos.id/login` (tidak di halaman publik). Tanpa subdomain: tetap login Filament `/dashboard/login`.
  Alternatif cookie `.gspos.id` bersama ditolak karena sesi pelanggan akan ikut terkirim ke `admin.`.
- Yang diubah: `bootstrap/app.php` (registrasi API), panel provider (`domain()` + `path('')`),
  `routes/web.php`, `config/pos.php`, `config/filesystems.php`, `config/scramble.php`, SPEC, `docs/api/`.
- Test mode subdomain: `tests/Feature/Routing/SubdomainRoutingTest.php`.
