# Standar Keamanan

Target: lolos uji penetrasi (pentest) sebelum rilis ke klien pertama. Acuan: **OWASP Top 10 (2021)**
dan **OWASP API Security Top 10 (2023)**.

Keamanan dibangun berlapis. Kode aplikasi saja **tidak cukup** untuk serangan seperti DDoS volumetrik
atau phishing email; lapisan hosting dan operasional (§4–§5) sama wajibnya.

```
Lapisan 1  Jaringan/DNS   Cloudflare (WAF, anti-DDoS), HTTPS
Lapisan 2  Web server     .htaccess: HTTPS, file tersembunyi, batas body, method
Lapisan 3  Aplikasi       Middleware, rate limit, validasi, otorisasi, envelope error
Lapisan 4  Data           Query terparameter, hash, enkripsi, isolasi tenant
Lapisan 5  Operasional    Patch dependency, backup, log & Sentry, 2FA, edukasi user
```

## 1. Pemetaan ancaman → kontrol

| Ancaman | Kontrol | Lokasi | Status |
|---|---|---|---|
| **SQL injection** | Hanya Eloquent/Query Builder dengan binding; raw SQL wajib `?` binding | `coding.md` §7, test `SecurityTest` | ✅ Langkah 2 |
| **XSS** | Blade `{{ }}` auto-escape; API hanya JSON + CSP `default-src 'none'`; `nosniff` | `SecurityHeaders` | ✅ Langkah 2 |
| **Clickjacking / phishing pembungkus** | `X-Frame-Options: DENY`, CSP `frame-ancestors 'none'` | `SecurityHeaders` | ✅ Langkah 2 |
| **Brute force login & PIN** | Rate limit `auth` 5/menit per IP+device, 20/menit per IP; kunci PIN 15 menit setelah 5 salah | `AppServiceProvider`, Action auth | ✅ Auth API (PIN terkunci dengan row lock, `PIN_LOCKED`) |
| **Flood / DoS aplikasi** | Rate limit `api` 120/menit per token; batas body 8 MB; pagination maks. 100 | `AppServiceProvider`, `.htaccess`, `config/pos.php` | ✅ Langkah 2 |
| **DDoS volumetrik** | Cloudflare proxy + "Under Attack mode" | Hosting (§4) | ⏳ Sebelum rilis |
| **Broken object level authorization (IDOR)** | Global scope tenant fail-closed + Policy; data tenant lain → 404; tenant_id tidak bisa disusupkan/diubah | `BelongsToTenant`, `SetTenantContext`, Policy | ✅ Langkah 3 (Policy per model menyusul) |
| **Broken function level authorization** | Permission hanya dari role (`Gate::before`), wildcard dibatasi daftar permission, menu tersembunyi | `UserRole`, `AppServiceProvider`, Filament | ✅ Langkah 3 (menu: langkah 5) |
| **Mass assignment** | `$fillable` eksplisit; `Model::shouldBeStrict()` di non-production | Model, `AppServiceProvider` | ✅ Langkah 2 |
| **Manipulasi harga dari client** | Nominal selalu dihitung ulang `OrderCalculator`; harga & opsi dari DB; field nominal kiriman diabaikan (ada test) | `CheckoutOrder` | ✅ Minggu 4 |
| **Replay / transaksi ganda** | ID client sebagai idempotency key + PK sebagai penjaga terakhir; nomor order & stok dengan row lock (diuji 30 checkout paralel di MySQL) | `CheckoutOrder`, `OrderNumberGenerator` | ✅ Minggu 4 |
| **Kebocoran informasi** | Error 500 tanpa detail (juga saat `APP_DEBUG=true`), tanpa `X-Powered-By`, 405 dijawab 404 | `ApiExceptionRenderer` | ✅ Langkah 2 |
| **Pencurian token / sesi** | Token per device dengan masa berlaku, bisa dicabut (token kasir terikat device); token user & device tidak bisa saling dipakai; cookie `HttpOnly`, `Secure`, `SameSite`, session terenkripsi | Sanctum, `EnsureUserToken`, `EnsureDeviceToken` | ✅ Auth API |
| **CSRF** | API memakai Bearer token (tanpa cookie); panel Filament memakai CSRF bawaan Laravel | Bawaan | ✅ |
| **CORS disalahgunakan** | Tidak ada origin yang diizinkan secara default | `config/cors.php` | ✅ Langkah 2 |
| **Log injection** | `X-Request-Id` dari client hanya diterima jika `[A-Za-z0-9-_]{8,64}` | `AssignRequestId` | ✅ Langkah 2 |
| **Data rahasia di log/session** | `dontFlash` PIN & password; dilarang log PIN/token | `bootstrap/app.php`, `coding.md` §9 | ✅ Langkah 2 |
| **Password lemah / bocor** | Min. 8, huruf + angka, cek HIBP di production; hash bcrypt | `Password::defaults()` | ✅ Langkah 2 |
| **Akun admin diambil alih (phishing)** | Guard & tabel terpisah, tanpa akun default, kata sandi admin min. 12 + huruf besar/kecil + simbol, 2FA (wajib secara default, on/off dengan konfirmasi kata sandi), rate limit login | `pos:create-admin`, Filament, ADR 0006 | ✅ Langkah 5 |
| **Karyawan nonaktif masih punya akses** | Provider auth hanya menerima user aktif; sesi lama ikut terputus | `TenantUserProvider` | ✅ Langkah 4 |
| **Enumerasi akun / karyawan** | Pesan & waktu respons sama untuk email tak terdaftar vs kata sandi salah; PIN salah vs user lain tenant | `LoginWithPassword`, `LoginWithPin` | ✅ Auth API |
| **Aplikasi usang / dimodifikasi** | `X-App-Version` wajib semver & ≥ `min_version` (426) | `CheckAppVersion` | ✅ Auth API |
| **Dokumentasi API bocor** | `/docs/api` hanya local & staging | Gate `viewApiDocs` | ✅ Auth API |
| **Formula injection di Excel** | Teks pengguna yang diawali `= + - @` diberi awalan `'`; nominal ditulis sebagai angka | `App\Exports\SafeCell` | ✅ Minggu 6 |
| **Data demo/akun lemah di production** | Seeder demo menolak berjalan di production | `DemoTenantSeeder` | ✅ Langkah 4 |
| **Dependency rentan (CVE)** | `composer audit` di `composer check` dan CI | CI | ✅ Langkah 2 |
| **Perintah merusak di production** | `DB::prohibitDestructiveCommands()` | `AppServiceProvider` | ✅ Langkah 2 |
| **Downgrade ke HTTP** | Redirect HTTPS di `.htaccess`, `URL::forceScheme('https')`, HSTS | `.htaccess`, `SecurityHeaders` | ✅ Langkah 2 |

## 2. Aturan kode (wajib)

1. **Dilarang** menyisipkan input ke SQL: `DB::raw("... $x")`, `whereRaw("id = $id")`,
   `orderBy($request->sort)`. Gunakan binding (`whereRaw('id = ?', [$id])`) dan **daftar putih**
   untuk nama kolom/urutan.
2. **Dilarang** `{!! !!}` di Blade untuk data dari user. Jika terpaksa, sanitasi dan beri komentar alasan.
3. Semua input lewat FormRequest dengan aturan **tipe + batas** (`max:` pada string, `max:` pada array
   — mis. item order maks. 100, opsi per item maks. 20).
4. Upload file: `image`/`mimes` + `max:` (KB), simpan dengan nama acak di disk non-publik bila sensitif,
   jangan percaya nama/ekstensi dari client.
5. Jangan pernah mengembalikan model utuh (`return $user`); selalu lewat API Resource.
6. Redirect hanya ke route internal (`redirect()->route()`), tidak ke URL dari input (open redirect = alat phishing).
7. Rahasia hanya di `.env`; `APP_DEBUG=false` di production.
8. Setiap endpoint baru: test isolasi tenant dan permission (`testing.md` §2).
9. `HtmlString` di Filament (deskripsi modal, label breadcrumb) **tidak di-escape** oleh Blade. Data user di dalamnya
   wajib `e()`: pakai `Layout::confirmText('… <strong>'.e($nama).'</strong>?', $catatan)` (catatan di-escape otomatis);
   `HasIconBreadcrumbs` meng-escape label sendiri. Diuji di `tests/Feature/Filament/PanelLayoutTest.php`.
10. Keluar panel lewat modal konfirmasi; aksinya sama dengan `LogoutController` Filament: logout guard,
    `session()->invalidate()`, `regenerateToken()`, lalu redirect ke halaman login internal.
11. Login pelanggan di `gspos.id/login` (ADR 0008): user dicari lintas tenant + `Hash::check` dengan hash dummy
    (waktu konstan), pesan umum "Email atau kata sandi salah", 5 percobaan/menit per email+IP. Sesi di `app.`
    hanya lewat tiket sekali pakai (acak 64, disimpan sebagai hash, 60 detik, `Cache::pull`). Parameter
    `next` hanya path relatif (`DashboardLoginTicket::safePath`: tolak URL absolut, `//`, `\`, karakter kontrol).
    Diuji di `tests/Feature/Routing/CentralLoginTest.php`.
12. Form publik (hubungi sales): honeypot `website` (bot mendapat respons sukses tanpa disimpan), throttle
    `contact` 3/menit & 10/jam per IP, CSRF, batas panjang tiap field, IP disimpan sebagai HMAC (bukan mentah).

## 3. Header keamanan (sudah aktif)

| Header | Nilai | Berlaku |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Semua |
| `X-Frame-Options` | `DENY` | Semua |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Semua |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | Semua |
| `Cross-Origin-Opener-Policy` | `same-origin` | Semua |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | HTTPS + production |
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | `/api/*` |
| `Cache-Control` | `no-store, private` | `/api/*` tanpa ETag |

CSP untuk panel Filament (butuh skrip Livewire/Alpine) disusun di langkah panel, mulai dalam mode
`Content-Security-Policy-Report-Only`.

## 4. Hosting cPanel (sebelum rilis)

- [ ] Domain di belakang **Cloudflare** (proxy oranye): WAF managed rules, Bot Fight Mode,
      rate limiting rule untuk `api.gspos.id/v1/auth/*` dan `admin.gspos.id/login`, SSL mode **Full (strict)**.
- [ ] Jika memakai Cloudflare, set trusted proxies agar IP asli terbaca (rate limit per IP akurat).
- [ ] AutoSSL aktif; ModSecurity cPanel aktif.
- [ ] Project di luar `public_html`; document root ke `public/` (SPEC Deploy).
- [ ] `.env` production: `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`.
- [ ] PHP `post_max_size` ≤ 8M, `expose_php=Off`, `display_errors=Off`.
- [ ] Izin file: folder 755, file 644, `.env` 600.
- [ ] Subdomain (ADR 0008): `SESSION_DOMAIN` **kosong** (cookie host-only — sesi `app.` tidak terbawa ke
      `admin.`); jangan diisi `.gspos.id`. Opsional: batasi `admin.gspos.id` per IP kantor di Cloudflare/cPanel.
- [ ] Route pengalih URL lama (`/dashboard/*`, `/admin/*`, `/api/v1/*` di domain utama) tanpa CSRF —
      aman karena hanya mengembalikan redirect ke host tetap dari env (bukan dari input pengguna).
- [ ] Backup harian terenkripsi & uji restore.

## 5. Anti-phishing (non-kode)

Phishing menyerang **manusia**, sehingga kontrolnya sebagian besar operasional:

- [ ] Email domain memakai **SPF, DKIM, DMARC** (`p=quarantine` → `p=reject`) agar email palsu atas
      nama domain ditolak.
- [ ] Panel hanya di satu domain resmi (`pos.domainanda.com`); umumkan ke klien bahwa login hanya di sana.
- [ ] Email sistem tidak pernah meminta password/PIN, dan tidak berisi tautan login langsung.
- [ ] Menu Keamanan: wajib 2FA super admin dalam posisi **ON** di production; disarankan juga untuk owner.
- [ ] Notifikasi ke owner saat device baru didaftarkan atau login dari device baru.

## 6. Checklist pra-pentest

- [ ] `composer check` hijau (termasuk `composer audit`).
- [ ] Semua endpoint punya test permission & isolasi tenant.
- [ ] Scan otomatis: OWASP ZAP baseline terhadap staging (`api.`, `app.`, `admin.` dan domain utama).
- [ ] Uji manual: IDOR antar tenant, brute force PIN, manipulasi nominal checkout, replay request,
      upload file berbahaya, akses `/admin` dengan akun tenant.
- [ ] Hasil scan & perbaikan dicatat di `docs/security/{YYYY-MM-DD}-pentest.md`.
