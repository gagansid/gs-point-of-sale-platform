# ADR 0025: Katalog fitur, kuota, dan add-on paket langganan

- Status: Diterima
- Tanggal: 2026-10-18 (revisi 2026-10-18: katalog fitur = tabel master, bukan enum)
- Pengambil keputusan: Gagan Suganda (usulan Claude, semua opsi disetujui 2026-10-18)
- Terkait: ADR 0024 (paket & tagihan), ADR 0010 (`max_outlets`), ADR 0009 (edisi, hanya-baca), SPEC Q108–Q112

## Konteks

ADR 0024 membatasi paket dengan 6 fitur tetap (`Plan::FEATURES`) dan satu batas angka (`max_outlets`). Untuk
menjual paket bertingkat (Basic/Pro/Bisnis) dan fitur satuan dibutuhkan:

- **A. Katalog fitur** yang lebih rinci.
- **B. Kuota** selain outlet.
- **C. Add-on** yang dibeli di atas paket.

Temuan saat survei kode (2026-10-18):

1. Fitur paket hanya dicek di **menu dashboard & mesin** (promo otomatis, self-order, kasbon). **API Flutter belum
   dicek**: `/members/*` dan `/vouchers/check` hanya melihat `MemberSetting::enabled`, sehingga tenant yang turun ke
   paket tanpa `member` (program member sudah aktif sebelumnya) masih bisa memakai member dari aplikasi kasir.
2. `auth/me` & `TenantResource` API tidak mengirim fitur/kuota, jadi Flutter tidak bisa menyembunyikan menu.
3. Tagihan hanya punya satu baris paket (`plan_id`, `plan_name`, `amount`); add-on butuh baris tagihan.

## Keputusan

### A. Katalog fitur

| Topik | Keputusan |
|---|---|
| Sumber katalog | **Master data tabel `features`** (`key` unik, nama, keterangan, urutan), **dikelola langsung di database tanpa CRUD** (revisi 2026-10-18 atas permintaan Gagan; usulan awal enum ditolak). Baris awal diisi migration agar production langsung punya datanya; fitur baru (A2) juga lewat migration. `plans.features` tetap JSON berisi key, divalidasi `exists:features,key`. `key` dipakai kode (`Features::enabled('member')`, `feature:member`), jadi mengganti key = perubahan kode. Menghapus baris = fitur tidak bisa dicentang lagi dan hilang dari `tenant.features` (tenant berpaket kehilangan akses) |
| Fitur baru | `advanced_reports`: tab Laporan **Laba kotor, Opsi, Karyawan, Waktu, Outlet** + endpoint `/reports/{profit,options,employees,heatmap}`. **Diskon & void tetap dasar** (revisi A2: kontrol kecurangan tidak boleh tergantung paket); `trend`, `hourly`, `channels`, `audit`, `dashboard` tetap dasar (Beranda & aplikasi). `/reports/purchases` & widget pembelian di tab Stok ikut fitur `stock`. `report_export`: export Excel/PDF laporan & member (`ReportExporter`, Member → Export); PDF rekap shift (API & kasir web) tetap bebas karena operasional. `web_cashier`: kasir web `/cashier` (login, setiap request lewat `SetCashierContext` termasuk Livewire, menu Kasir di dashboard); aplikasi Flutter tetap. `online_payment`: pembayaran online pelanggan via Midtrans owner (ADR 0014) — tanpa fitur, pelanggan diarahkan bayar di kasir (`PAYMENT_GATEWAY_NOT_CONFIGURED`), halaman Pembayaran online tersembunyi; webhook Midtrans tetap diproses (uang yang sudah dibayar diakui). **Paket yang sudah ada** diberi `advanced_reports`, `report_export`, `web_cashier` (dulu gratis) dan `online_payment` bila punya `self_order`, lewat migration |
| Selalu tersedia | Kasir (Flutter), shift, kas masuk/keluar, retur, pecah/gabung bill, laporan dasar (Ringkasan, Produk, Kategori, Metode bayar, Shift, Jenis order, Stok sederhana), karyawan & role, perangkat |
| Pengecekan | Satu pintu `Features::enabled('key')` / `Features::ensure('key')`. Dashboard: `canAccess()`/`shouldRegisterNavigation()` resource & tab. **API: middleware `feature:{key}`** pada grup route (menutup temuan 1). Mesin/Action tetap mengecek (pertahanan berlapis) |
| Error API | Kode baru **`FEATURE_NOT_AVAILABLE`** (403), `details.feature` = key. Dibedakan dari `FORBIDDEN` agar Flutter bisa menampilkan "Fitur tidak termasuk paket Anda" alih-alih "Tidak punya akses" |
| Flutter | `auth/me` → `data.tenant.features` (list key yang aktif; tenant tanpa paket/jual putus = semua key). Perlu rilis Flutter untuk menyembunyikan menu; sebelum itu server tetap menolak dengan kode di atas |
| Data lama | Fitur yang mati menyembunyikan menu & menolak tulis/baca lewat API; data tidak dihapus (sama ADR 0024) |

### B. Kuota

| Topik | Keputusan |
|---|---|
| Jenis | Enum `App\Enums\Quota`: `outlets` (outlet aktif), `users` (karyawan aktif, owner dihitung), `devices` (perangkat kasir tidak dicabut, Flutter + kasir web), `products` (produk aktif, tidak terhapus), `members` (member terdaftar). **Tidak** ada kuota transaksi/bulan: memblokir checkout di tengah jam ramai merugikan kasir |
| Penyimpanan | `plans.limits` JSON `{quota: int|null}`, null/tidak ada = tanpa batas. `tenants.limit_overrides` JSON untuk batas khusus dari admin (menggantikan arti "manual" `tenants.max_outlets`). Migrasi: `plans.max_outlets` → `limits.outlets`, `tenants.max_outlets` → `limit_overrides.outlets`; kolom lama dihapus setelah semua pemakai pindah ke `Tenant::limit(Quota)` |
| Batas efektif | `override ?? (limit paket + tambahan add-on)`; tenant tanpa paket = tanpa batas kecuali override; jual putus = tanpa batas |
| Pengecekan | Satu pintu `Quotas::ensureAvailable(Tenant, Quota, int $adding = 1)` di Action yang **membuat atau mengaktifkan** data (`CreateOutlet`/aktifkan, `SaveEmployee`/`SetEmployeeActive`, registrasi perangkat API & `RegisterWebDevice`, simpan/aktifkan produk API & dashboard, daftar member API & dashboard), di dalam transaksi dengan `lockForUpdate` baris tenant agar dua request bersamaan tidak sama-sama lolos |
| Error API | Kode baru **`PLAN_LIMIT_REACHED`** (409), `details: {quota, limit, used}`. `OUTLET_LIMIT_REACHED` tetap dipakai untuk outlet (kompatibel Flutter versi lama) |
| Lewat batas setelah downgrade | Data lama **tetap jalan** (produk tetap dijual, karyawan tetap login, perangkat tetap dipakai); hanya **tambah/aktifkan** yang ditolak sampai pemakaian di bawah batas. Dashboard menampilkan banner "Pemakaian melebihi paket" + pemakaian vs batas di Langganan |
| Tampilan | Owner: Pengaturan → Langganan menampilkan pemakaian/batas per kuota. Admin: detail bisnis section Langganan + form paket. `auth/me` → `data.tenant.limits` `{quota: {limit, used}}` |

### C. Add-on

| Topik | Keputusan |
|---|---|
| Model | `addons` (platform, dikelola admin): kode, nama, keterangan, harga bulanan & tahunan, **jenis** `feature` (memberi satu `Feature`) atau `quota` (menambah `Quota` sebanyak `quota_amount` per unit, mis. +1 outlet), boleh beberapa unit (hanya jenis kuota), aktif/publik, urutan |
| Langganan add-on | `tenant_addons`: tenant, addon, `quantity`, `status` (`active`/`cancelling`), `cancel_at`. **Satu masa aktif dengan paket**: siklus & `subscription_ends_at` mengikuti tenant, tidak ada tanggal perpanjangan terpisah |
| Syarat | Hanya tenant **berpaket** di edisi SaaS (tanpa paket = semua fitur, tanpa batas). Add-on fitur yang sudah termasuk paket tidak bisa dibeli. Trial boleh membeli bersama tagihan paket pertama |
| Baris tagihan | Tabel baru `subscription_invoice_items` (jenis `plan`/`addon`, ref id, nama snapshot, qty, harga satuan, jumlah). `subscription_invoices.amount` = jumlah baris. Kolom `plan_*` lama tetap (baris paket) agar riwayat & webhook tidak berubah; migrasi membuat satu baris `plan` untuk tagihan lama |
| Tagihan | **Perpanjangan** = paket + semua add-on aktif (yang `cancelling` tidak ikut). **Tambah add-on di tengah masa aktif** = jenis tagihan baru `addon`, prorata seperti upgrade (harga × sisa hari ÷ hari satu siklus, dibulatkan ke rupiah), add-on aktif saat lunas. Tetap **satu tagihan menunggu per tenant** (ADR 0024): membuat tagihan baru membatalkan yang lama, dengan peringatan di modal |
| Berhenti | Owner/admin **Berhenti** = `cancelling`, tetap aktif sampai akhir masa aktif lalu dilepas oleh `pos:apply-plan-changes`; tanpa pengembalian dana. Mengurangi unit kuota = sama (berlaku akhir masa aktif) |
| Downgrade paket | Add-on tetap; add-on fitur yang ternyata termasuk paket baru otomatis `cancelling` |
| Pemakaian | `Features::enabled` = fitur paket ∪ fitur add-on aktif. Batas kuota = limit paket + Σ(`quota_amount` × qty) add-on kuota aktif (paket tanpa batas tetap tanpa batas) |
| Admin | Pelanggan → **Add-on** (CRUD); detail bisnis: daftar add-on + **Atur add-on** tanpa tagihan (seperti Atur paket); Buat tagihan admin bisa menyertakan add-on |
| Owner | Langganan: section Add-on (aktif, harga, tombol **Tambah** → tagihan prorata, **Berhenti**) |

### Tahapan implementasi (satu langkah per giliran, masing-masing dengan test & docs)

| Langkah | Isi |
|---|---|
| **A1** | Tabel master `features`, refactor `Plan::FEATURES`/`Features`, middleware API `feature:*` untuk 6 fitur lama (**menutup celah API member/voucher/kasbon**), `FEATURE_NOT_AVAILABLE`, `auth/me` `tenant.features` |
| **A2** | Fitur baru `advanced_reports`, `report_export`, `web_cashier`, `online_payment` (dashboard + API + kasir web) |
| **B1** | `Quota`, `plans.limits`, `tenants.limit_overrides`, migrasi `max_outlets`, `Quotas::ensureAvailable` + `PLAN_LIMIT_REACHED` di Action |
| **B2** | Tampilan pemakaian (owner, admin, `auth/me` `tenant.limits`), banner lewat batas, form paket admin |
| **C1** | `addons`, `tenant_addons`, resource admin, Atur add-on, union fitur & kuota |
| **C2** | `subscription_invoice_items`, perpanjangan dengan add-on, tagihan `addon` prorata, Berhenti, halaman Langganan owner, email |

## Konsekuensi

- Positif: celah API ditutup di A1; paket bertingkat & fitur satuan bisa dijual tanpa ubah kode per tenant.
- Positif: Flutter mendapat daftar fitur & kuota dari server; server tetap sumber kebenaran.
- Negatif: dua kode error baru → aplikasi Flutter perlu rilis untuk pesan yang ramah (sebelum itu tampil pesan
  server). Baris fitur baru di tabel tanpa pengecekan di kode tidak membatasi apa pun (hanya label); fitur yang benar-benar
  membatasi tetap butuh rilis server.
- Negatif: query hitung pemakaian di setiap tambah data (ringan: `COUNT` berindeks `tenant_id`), `lockForUpdate`
  baris tenant menyerialkan penambahan data per tenant (jarang & singkat).
- Negatif: add-on mengikuti masa aktif paket, jadi tidak bisa punya tanggal perpanjangan sendiri (sengaja, agar satu
  tagihan per periode).
