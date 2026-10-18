# ADR 0025: Katalog fitur, kuota, dan add-on paket langganan

- Status: Diterima
- Tanggal: 2026-10-18 (revisi 2026-10-18: katalog fitur & kuota = tabel master, bukan enum)
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
| Jenis | **Master data tabel `quotas`** (revisi B1: bukan enum, dikelola langsung di database tanpa CRUD, baris awal lewat migration): `outlets` (outlet aktif), `users` (karyawan aktif, owner dihitung), `devices` (perangkat tidak dicabut, Flutter + kasir web; device kasir web menganggur dicabut otomatis / slotnya dipakai ulang — SPEC Q116), `products` (produk & paket aktif belum dihapus; **bahan baku tidak dihitung**), `members` (member aktif). Cara menghitung per key ada di `App\Support\Quotas` (key tanpa penghitung = hanya label). **Tidak** ada kuota transaksi/bulan: memblokir checkout di tengah jam ramai merugikan kasir |
| Penyimpanan | `plans.limits` JSON `{key: int}` (tidak ada = tanpa batas) dan `tenants.limit_overrides` JSON (batas khusus admin, form Ubah bisnis). Migrasi: `plans.max_outlets` → `limits.outlets`; `tenants.max_outlets` yang sama dengan paketnya dibuang (dulu salinan), selain itu jadi `limit_overrides.outlets`; kedua kolom lama **dihapus**. Trial baru: `limit_overrides.outlets` = `POS_DEFAULT_MAX_OUTLETS` (sama dengan sebelumnya) |
| Batas efektif | `limit_overrides[key] ?? limit paket (+ tambahan add-on, C1)`; tanpa keduanya = tanpa batas; jual putus = tanpa batas. **Menerapkan paket** (tagihan lunas, Atur paket admin, perubahan terjadwal) **menghapus batas khusus**, sama seperti dulu `max_outlets` ditimpa paket; melepas paket mempertahankannya |
| Pengecekan | Satu pintu `Quotas::ensureAvailable(tenantId, key)` di dalam transaksi Action, mengunci baris tenant (`lockForUpdate`) agar dua request bersamaan tidak sama-sama lolos: `CreateOutlet`/`SetOutletActive`, `SaveEmployee` (baru) & `SetEmployeeActive` (aktif lagi), `RegisterDevice` (baru / dicabut lalu didaftarkan lagi; daftar ulang perangkat aktif tidak dihitung) & `RegisterWebDevice` & `SetDeviceRevoked` (aktif lagi), `SaveProduct` (baru, aktif lagi, bahan baku jadi produk; termasuk import & template menu — import ditolak utuh), `SaveMember` (dashboard, API, kasir web, import, daftar mandiri) & `SetMemberActive`. Idempotent replay tidak dihitung |
| Error API | Kode baru **`PLAN_LIMIT_REACHED`** (409), `details: {quota, limit, used}`, pesan menyebut nama kuota. `OUTLET_LIMIT_REACHED` tetap dipakai untuk outlet (kompatibel Flutter versi lama), `details` = `{max_outlets, quota, limit, used}`. Daftar mandiri member publik menampilkan pesan umum (angka kuota tidak dibuka ke publik) |
| Lewat batas setelah downgrade | Data lama **tetap jalan** (produk tetap dijual, karyawan tetap login, perangkat tetap dipakai); hanya **tambah/aktifkan** yang ditolak sampai pemakaian di bawah batas. Dashboard menampilkan banner "Pemakaian melebihi paket" + pemakaian vs batas di Langganan |
| Tampilan | `Quotas::usage()` satu sumber: owner **Pengaturan → Langganan** section *Pemakaian kuota* (bar per kuota: kuning ≥ 80%, merah melebihi batas); admin detail bisnis (tampilan sama); **banner** di setiap halaman dashboard bila ada kuota melebihi batas, hanya untuk `billing.manage` (owner), hanya menghitung kuota yang punya batas; `auth/me` → `data.tenant.limits` `{key: {limit, used}}`. Form paket admin: bagian *Kuota*; form ubah bisnis: *Batas khusus* |

### C. Add-on

| Topik | Keputusan |
|---|---|
| Model | `addons` (platform, dikelola admin): kode, nama, keterangan, harga bulanan & tahunan, **jenis** `feature` (memberi satu `Feature`) atau `quota` (menambah `Quota` sebanyak `quota_amount` per unit, mis. +1 outlet), boleh beberapa unit (hanya jenis kuota), aktif/publik, urutan |
| Langganan add-on | `tenant_addons`: tenant, addon, `quantity`, `status` (`active`/`cancelling`), `cancel_at`. **Satu masa aktif dengan paket**: siklus & `subscription_ends_at` mengikuti tenant, tidak ada tanggal perpanjangan terpisah |
| Syarat | Hanya tenant **berpaket** di edisi SaaS (tanpa paket = semua fitur, tanpa batas). Add-on fitur yang sudah termasuk paket tidak bisa dibeli. Trial boleh membeli bersama tagihan paket pertama |
| Baris tagihan | Tabel `subscription_invoice_items` (jenis `plan`/`addon`, ref id, nama snapshot, unit, harga satuan, jumlah). `subscription_invoices.amount` = jumlah baris. Kolom `plan_*` tetap (paket konteks tagihan; untuk tagihan add-on = paket tenant saat itu) agar riwayat & webhook tidak berubah; migrasi membuat satu baris `plan` untuk tagihan lama. Uraian tampil lewat `SubscriptionInvoice::description()` (tabel, detail bisnis, email) |
| Tagihan | **Perpanjangan** = paket + add-on `active` (yang `cancelling`, atau add-on fitur yang sudah termasuk paket tujuan, tidak ikut) dengan harga siklus tagihan. **Upgrade** = selisih paket saja. **Tambah add-on** di tengah masa aktif berbayar = jenis tagihan `addon` (`CreateAddonInvoice`), prorata seperti upgrade (harga × unit × sisa hari ÷ hari satu siklus tenant, dibulatkan ke rupiah), jatuh tempo ≤ akhir masa aktif, add-on aktif saat lunas (unit kuota ditambahkan). Saat trial belum bisa membeli add-on (bayar paket dulu, atau admin Atur add-on). Owner: add-on aktif & publik; admin (**Tagih add-on**): termasuk khusus. Tetap **satu tagihan menunggu per tenant** (ADR 0024), modal memperingatkan |
| Berhenti | Owner (Langganan) **Berhenti** / **Lanjutkan** (`StopTenantAddon`): `cancelling` sampai akhir masa aktif berbayar lalu dilepas `pos:apply-plan-changes`; tanpa masa aktif berjalan = langsung dilepas; tanpa pengembalian dana. Mengurangi sebagian unit belum didukung (berhenti lalu beli ulang); admin bisa mengatur unit langsung lewat Atur add-on |
| Downgrade paket | Add-on tetap. Saat paket diterapkan (lunas, Atur paket, jadwal), add-on fitur yang ternyata termasuk paket otomatis `cancelling` sampai akhir masa aktif (`TenantAddons::cancelRedundant`) |
| Pemakaian | `Features::enabled` = fitur paket ∪ fitur add-on aktif. Batas kuota = limit paket + Σ(`quota_amount` × qty) add-on kuota aktif (paket tanpa batas tetap tanpa batas) |
| Admin | Pelanggan → **Add-on** (CRUD admin, `SaveAddon`; berbeda dengan master data fitur/kuota karena harga add-on keputusan bisnis yang sering berubah; jenis & manfaat terkunci setelah dipakai bisnis, tidak ada hapus — nonaktifkan); detail bisnis: daftar add-on + **Atur add-on** tanpa tagihan (`SetTenantAddons`: keadaan akhir `{addon_id: unit}`, 0 = lepas langsung, bisnis harus berpaket, fitur yang sudah di paket ditolak, add-on nonaktif hanya boleh dipertahankan); Buat tagihan admin bisa menyertakan add-on (C2) |
| Owner | Langganan: section **Add-on** (nama, manfaat, harga per siklus, status, Berhenti/Lanjutkan) + **Tambah add-on** (pratinjau prorata); riwayat & tagihan menunggu menampilkan rincian baris |

### Tahapan implementasi (satu langkah per giliran, masing-masing dengan test & docs)

| Langkah | Isi |
|---|---|
| **A1** | Tabel master `features`, refactor `Plan::FEATURES`/`Features`, middleware API `feature:*` untuk 6 fitur lama (**menutup celah API member/voucher/kasbon**), `FEATURE_NOT_AVAILABLE`, `auth/me` `tenant.features` |
| **A2** | Fitur baru `advanced_reports`, `report_export`, `web_cashier`, `online_payment` (dashboard + API + kasir web) |
| **B1** | Tabel master `quotas`, `plans.limits`, `tenants.limit_overrides`, migrasi & hapus `max_outlets`, `Quotas::ensureAvailable` + `PLAN_LIMIT_REACHED` di Action |
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
