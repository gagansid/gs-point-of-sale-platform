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
| **Brute force login & PIN** | Rate limit `auth` 5/menit per IP+device, 20/menit per IP; kunci PIN 15 menit setelah 5 salah per sumber (login/approval per perangkat), global setelah 15 salah, 20 salah per perangkat → 429 | `AppServiceProvider`, `VerifyPin` | ✅ Auth API (`PIN_LOCKED`; penghitung per sumber agar perangkat dicuri tidak bisa mengunci karyawan lain — GSPOS-SEC-002) |
| **Flood / DoS aplikasi** | Rate limit `api` 120/menit per token; batas body 8 MB; pagination maks. 100 | `AppServiceProvider`, `.htaccess`, `config/pos.php` | ✅ Langkah 2 |
| **DDoS volumetrik** | Cloudflare proxy + "Under Attack mode" | Hosting (§4) | ⏳ Sebelum rilis |
| **Broken object level authorization (IDOR)** | Global scope tenant fail-closed + Policy; data tenant lain → 404; tenant_id tidak bisa disusupkan/diubah | `BelongsToTenant`, `SetTenantContext`, Policy | ✅ Langkah 3 (Policy per model menyusul) |
| **Broken function level authorization** | Permission hanya dari role (`Gate::before`), wildcard dibatasi daftar permission, menu tersembunyi | `UserRole`, `AppServiceProvider`, Filament | ✅ Langkah 3 (menu: langkah 5) |
| **Akses outlet lain lewat form menu (ADR 0011)** | "Dijual di outlet" hanya mengubah outlet aktif yang dipegang user; ID outlet di luar pilihan ditolak validasi form dan diabaikan Action (juga ID tenant lain); baris per outlet tenant-scoped | `SetProductListing`, `ProductForm`, `OutletMenu*Test` | ✅ Menu per outlet P3–P4 |
| **Order outlet lain lewat replay / perangkat lain (ADR 0010)** | `Idempotency::existing` wajib callback otorisasi untuk data per outlet/user; replay checkout hanya order di outlet perangkat (dibuat pelaku / perangkat yang sama / boleh dilihat); bayar, ubah open bill, dan void dari perangkat hanya untuk order di outlet perangkat → selain itu 404 | `Idempotency`, `CheckoutOrder::ensureAtOutlet`, `GspoSec001*`, `GspoSec011*` | ✅ GSPOS-SEC-001, SEC-011 |
| **Approver PIN dari outlet lain** | Approver wajib punya permission **dan** ditugaskan di outlet order (owner = semua outlet) | `VerifyApproval`, `GspoSec003ApproverOutletTest` | ✅ GSPOS-SEC-003 |
| **Profil diubah tanpa aturan akun** | Halaman Profil dashboard lewat `UpdateOwnProfile`: email huruf kecil + verifikasi ulang, ganti kata sandi mencabut semua token API | `Pages\Auth\EditProfile`, `UpdateOwnProfile`, `GspoSec012ProfileUpdateTest` | ✅ GSPOS-SEC-012, SEC-013 |
| **Email karyawan dipakai membanjiri kotak masuk / diklaim orang lain** | Email baru/diganti wajib diverifikasi pemiliknya (link bertanda tangan 3 hari, hash email → link lama mati bila email diganti); kirim ulang maks. 3×/10 menit per karyawan | `SaveEmployee`, `SendEmployeeVerification`, `EmployeeEmailVerificationTest` | ✅ SPEC Q46 |
| **Laporan/beranda membocorkan data outlet atau tenant lain** | Semua angka lewat `ReportService` (TenantScope + `CurrentOutlet::scope`); outlet beranda = pemilih sidebar (`CurrentOutlet::choose`, outlet di luar penugasan → 404); filter `payment_method_ids[]`/`user_ids[]` divalidasi UUID & hanya mempersempit query yang sudah tenant-scoped (ID asing = hasil kosong); tanggal dari URL dinormalisasi (maks. 366 hari, tidak melewati hari ini, input rusak = hari ini); panel audit hanya `report.view` | `ReportService`, `ReportFilters`, `ReportPeriod`, `Pages\Home`, `ReportTest`, `HomeDashboardTest` | ✅ Beranda dashboard |
| **Struk transaksi bocor / diakses lintas tenant** | `/dashboard/orders/{id}/receipt`: wajib login panel, order dicari lewat query tenant-scoped (lain tenant/outlet → 404), `Gate reprint` (order.reprint + visibilitas order), `Cache-Control: no-store`; teks pengguna di-escape | `ReceiptController`, `ReceiptReprintTest` | ✅ T3 |
| **Mass assignment** | `$fillable` eksplisit; `Model::shouldBeStrict()` di non-production | Model, `AppServiceProvider` | ✅ Langkah 2 |
| **Manipulasi harga dari client** | Nominal selalu dihitung ulang `OrderCalculator`; harga & opsi dari DB; field nominal kiriman diabaikan (ada test) | `CheckoutOrder` | ✅ Minggu 4 |
| **Replay / transaksi ganda** | ID client sebagai idempotency key + PK sebagai penjaga terakhir; nomor order & stok dengan row lock (diuji 30 checkout paralel di MySQL) | `CheckoutOrder`, `OrderNumberGenerator` | ✅ Minggu 4 |
| **Kebocoran informasi** | Error 500 tanpa detail (juga saat `APP_DEBUG=true`), tanpa `X-Powered-By`, 405 dijawab 404; berlaku di `/api/v1` maupun `api.gspos.id/v1` (deteksi request API satu sumber: `Domains::isApiRequest`). Web: halaman error bermerek `resources/views/errors` tanpa pesan exception, 5xx menampilkan ID permintaan | `ApiExceptionRenderer`, `x-site.error-page`, `GspoSec009*`, `Site/ErrorPagesTest` | ✅ Langkah 2, GSPOS-SEC-009 |
| **Pencurian token / sesi** | Token per device dengan masa berlaku, bisa dicabut (token kasir terikat device); token user & device tidak bisa saling dipakai; cookie `HttpOnly`, `Secure`, `SameSite`, session terenkripsi | Sanctum, `EnsureUserToken`, `EnsureDeviceToken` | ✅ Auth API |
| **Host header poisoning (link reset/verifikasi ke domain penyerang)** | `TrustHosts` hanya host APP_URL + `POS_*_DOMAIN` (aktif di luar `local`); host lain → 400. Deploy: pastikan `APP_URL`/domain sesuai host publik (alias `www.` perlu redirect di server atau ditambahkan) | `bootstrap/app.php`, `Domains::trustedHostPatterns`, `GspoSec016TrustedHostsTest` | ✅ GSPOS-SEC-016 |
| **IP/skema salah di belakang proxy (rate limit dibagi semua pengguna, HSTS tidak terkirim)** | `POS_TRUSTED_PROXIES` (kosong / `cloudflare` / IP-CIDR); hanya `X-Forwarded-For` & `X-Forwarded-Proto` yang dipercaya (bukan `X-Forwarded-Host`); URL antar subdomain selalu https di production. Cloudflare: SSL mode **Full (strict)** | `TrustedProxies`, `AppServiceProvider`, `Domains::url`, `GspoSec015TrustedProxiesTest` | ✅ GSPOS-SEC-015 (isi `.env` saat deploy) |
| **CSRF** | API memakai Bearer token (tanpa cookie); panel Filament memakai CSRF bawaan Laravel | Bawaan | ✅ |
| **CORS disalahgunakan** | Tidak ada origin yang diizinkan secara default | `config/cors.php` | ✅ Langkah 2 |
| **Log injection** | `X-Request-Id` dari client hanya diterima jika `[A-Za-z0-9-_]{8,64}` | `AssignRequestId` | ✅ Langkah 2 |
| **Data rahasia di log/session** | `dontFlash` PIN & password; dilarang log PIN/token | `bootstrap/app.php`, `coding.md` §9 | ✅ Langkah 2 |
| **Password lemah / bocor** | Min. 8, huruf + angka, cek HIBP di production; hash bcrypt | `Password::defaults()` | ✅ Langkah 2 |
| **Akun admin diambil alih (phishing)** | Guard & tabel terpisah, tanpa akun default, kata sandi admin min. 12 + huruf besar/kecil + simbol, 2FA (wajib secara default, on/off dengan konfirmasi kata sandi), rate limit login | `pos:create-admin`, Filament, ADR 0006 | ✅ Langkah 5 |
| **Karyawan nonaktif masih punya akses** | Provider auth hanya menerima user aktif; sesi lama ikut terputus | `TenantUserProvider` | ✅ Langkah 4 |
| **Enumerasi akun / karyawan** | Pesan & waktu respons sama untuk email tak terdaftar vs kata sandi salah (hash dummy di cache, `DummyPasswordHash` — GSPOS-SEC-010); PIN salah vs user lain tenant | `LoginWithPassword`, `DashboardLoginController`, `LoginWithPin` | ✅ Auth API |
| **Aplikasi usang / dimodifikasi** | `X-App-Version` wajib semver & ≥ `min_version` (426) | `CheckAppVersion` | ✅ Auth API |
| **Dokumentasi API bocor** | `/docs/api` hanya local & staging | Gate `viewApiDocs` | ✅ Auth API |
| **Akses laporan melebihi peran / PDF sebagai vektor serangan** | Izin per tab & endpoint dari `ReportTab` (Q49); supervisor maks. 31 hari dipotong di `ReportContext` (web) / `422` di `ReportRequest` (API); kasir hanya rekap shift sendiri (`ShiftRecap::canView`); supervisor di panel hanya Laporan (Q50: Penjualan/Shift/cetak ulang struk butuh `hasFullDashboard`); dompdf tanpa PHP, remote resource, & JavaScript; export ditolak bila > batas baris | `ReportTab`, `ReportContext`, `ReportRequest`, `ShiftRecap`, `config/dompdf.php`, `ReportModuleApiTest`, `ReportsPageTest`, `ReportExportTest` | ✅ Modul laporan |
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
13. Daftar mandiri `gspos.id/register` (ADR 0009): CSRF, honeypot, throttle `signup` 5/jam & 20/hari per IP
    (kiriman bot ikut dihitung), email unik global (huruf kecil), `Password::defaults()` + konfirmasi.
    Owner baru **belum terverifikasi**: checkout & pembayaran ditolak `EMAIL_NOT_VERIFIED`. Link verifikasi =
    `URL::temporarySignedRoute` 3 hari + `sha1(email)` (link lama mati bila email diganti), throttle 6/menit;
    kirim ulang hanya dari panel (login) 3 per 10 menit.
14. Kata sandi (SPEC Q34): lupa kata sandi `gspos.id/forgot-password` (respons sama untuk email terdaftar/tidak,
    5/menit, broker throttle 60 detik, link 60 menit, hanya user aktif). Undangan owner dari admin/lead = token
    di **tabel terpisah** `user_invitation_tokens` (3 hari) agar link reset tidak ikut berlaku 3 hari. Reset
    berhasil → email terverifikasi, `remember_token` diganti, semua token Sanctum dihapus. Kata sandi owner
    tidak pernah dikirim sebagai teks.
12. Form publik (hubungi sales): honeypot `website` (bot mendapat respons sukses tanpa disimpan), throttle
    `contact` 3/menit & 10/jam per IP, CSRF, batas panjang tiap field, IP disimpan sebagai HMAC (bukan mentah).

15. Kasir web `/cashier` (SPEC Q54): guard `cashier` terpisah dari `/dashboard`; login per akun+IP 5/menit dan
    per IP 20 gagal/menit; pesan khusus hanya setelah kata sandi benar. Setiap request (termasuk `/livewire/update`
    lewat middleware persisten) memeriksa ulang akses, kata sandi (sidik HMAC di sesi), bisnis, device, dan outlet.
    State Livewire dianggap input tidak tepercaya: ID order/bill `#[Locked]`, keranjang divalidasi dengan aturan
    FormRequest API sebelum disimpan, harga selalu dari server, PIN approver tidak disimpan di snapshot. Penghitung
    PIN approval web per pelaku (cookie browser bisa dihapus); maks. device web aktif per outlet
    (`POS_WEB_CASHIER_MAX_DEVICES`). Error bisnis di route kasir kembali ke URL sebelumnya dari sesi (bukan Referer).
16. Panel admin: aksi sensitif (tambah/hapus admin, reset 2FA, maintenance, kosongkan log, hapus backup) minta kata
    sandi admin yang login. Log Viewer & Backup memilih file dari daftar (anti path traversal); isi log di-escape.
    Backup berisi hash kata sandi/PIN: `BACKUP_ARCHIVE_PASSWORD` wajib di production. Sentry tanpa PII.

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
