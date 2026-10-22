# Catatan Lanjut Pengerjaan

> Diperbarui: 2026-10-22 · Branch: `feature/pos` (semua di-push)
> Status: fitur MVP + backlog ADR 0013–0025 selesai; sesi 2026-10-19–21: rapikan admin, halaman masuk + Turnstile,
> pembayaran tagihan (QRIS/VA/transfer manual + bukti), master bank, PDF & email tagihan, Midtrans vendor 2 mode,
> riwayat perubahan admin, pembersihan bukti transfer (SPEC Q118–Q127). Deploy ditunda atas keputusan pemilik.
> Kualitas: 1994 test lulus (SQLite), Pint & Larastan level 6 bersih; test pembayaran/bank/audit juga lulus di MySQL
> `gspos_test`. Paket hosting `dist/hosting` dibangun ulang 2026-10-21 (lihat §2 "Deploy").
> Rincian per tahap tetap di [`docs/PROGRESS.md`](PROGRESS.md); dokumen ini ringkasan untuk melanjutkan.

---

## 1. Sudah dikerjakan

### Sesi 2026-10-19 – 2026-10-22 (SPEC Q122–Q129) — semua di-push
| Bagian | Isi |
|---|---|
| Halaman masuk (Q122) | Login owner/admin/kasir, daftar, lupa & atur kata sandi seragam (field 44px berikon, panel brand); Cloudflare Turnstile per halaman (Sistem → Keamanan, key terenkripsi); mode gelap panel: field fokus gelap, tombol terang |
| Data demo | `DemoFullDataSeeder` (lokal/testing): 35 hari transaksi lewat Action di 2 outlet Kopi Senja, stok, PO, transfer, opname, member, kasbon, promo — target omzet & stok realistis |
| Pembayaran tagihan (Q123) | Owner **Bayar sekarang**: QRIS / VA (Snap Midtrans vendor) atau transfer manual + unggah bukti (disk privat); admin tab **Perlu verifikasi** → Tandai lunas / Tolak bukti. Setelan **Sistem → Pembayaran langganan**: rekening, QRIS statis, petunjuk, **Midtrans 2 mode** (Sandbox/Produksi, key per mode terenkripsi & diuji, cadangan `.env`) |
| Master bank (Q124) | Tabel `banks` (12 bank diisi migrasi), **Sistem → Bank**: logo vektor otomatis (singkatan + warna) atau unggah PNG/JPG/WEBP |
| UI dashboard (Q125) | Outlet (kolom alamat, paginasi), Riwayat perubahan (Kolom · Sebelum · Sesudah, tab, Detail), halaman Langganan baru (kartu paket, tagihan, **kuota berupa kartu**, riwayat tagihan = tabel standar dengan cari/filter/paginasi) |
| Dokumen & email (Q126) | PDF A4 *Tagihan* / *Kuitansi* (cap LUNAS, terbilang) diunduh owner & admin; email bukti dikirim → admin, ditolak → owner, lunas → owner + kuitansi PDF; template email bermerek gs.POS |
| Audit & pembersihan (Q127) | **Sistem → Riwayat perubahan** (admin): setiap perubahan setelan platform & bank, rahasia disamarkan; `pos:prune-payment-proofs` harian 03.45 WIB (bukti tagihan batal/kedaluwarsa > `POS_BILLING_PROOF_RETENTION_DAYS` hari & unggahan yatim > 1 hari) |
| UI & tampilan bisnis (Q128) | Rupiah bulat di laporan, panduan awal pintar, bawaan terang, toast seragam, layar kecil, halaman error baru; audit menu owner/admin; **Pengaturan → Bisnis & tampilan** (owner): logo, warna utama 8 preset/bebas, sidebar berwarna/terang, mode bawaan |
| Opsi bawaan & kasir (Q129) | Owner mengatur opsi **Bawaan** per grup (Gula/Es wajib dengan bawaan Normal, Tambahan opsional); kasir web & self-order memilihnya otomatis (`OptionDefaults`); diskon item berformat rupiah; dialog **Pembayaran berhasil** baru (kembalian besar, uang diterima, Enter = transaksi baru) |
| Perbaikan | Tab admin Tagihan/Admin error 500 (parameter closure Filament harus bernama `$query`); badge pesanan meja kasir web (`x-on="try …"` = SyntaxError Alpine, bunyi tidak pernah keluar) + test pemindai atribut Alpine |

Env baru (lihat `.env.example`): `POS_BILLING_MIDTRANS_SANDBOX_SERVER_KEY`, `POS_BILLING_MIDTRANS_PRODUCTION_SERVER_KEY`,
`POS_BILLING_ISSUER_NAME/ADDRESS/EMAIL` (kop PDF), `POS_BILLING_PROOF_RETENTION_DAYS`. Isi penerbit sebelum dipakai.

Catatan untuk tim Flutter: **pembulatan grand total selalu ke atas** (`docs/api/order.md` §Aturan 1, sebelumnya tertulis
half-up). Pratinjau keranjang memakai `ceil(total / rounding) × rounding` dengan aritmetika desimal, atau `POST /orders/quote`.
Repo aplikasi tidak tersedia di mesin pengembangan platform; perubahan aplikasi belum dikerjakan.

### Rapikan panel /admin (2026-10-19, SPEC Q118–Q121) — di-push (`a9ed5c5`)
- Standar UI: Select & DatePicker JS seragam (`FieldDefaults`, ikon kalender bisa diklik, zona waktu WIB di /admin),
  tombol **+ Tambah** di header card (Paket, Add-on, Pengumuman, Admin, Versi aplikasi, Tagihan), judul card berikon,
  footer paginasi, breadcrumb seragam (`Menu › Daftar`, `Grup › Judul`), modal jarak atas = bawah & scroll di dalam,
  dropdown Select di repeater tabel tidak terpotong.
- Paket: kolom Kode, cari, tab aktif/nonaktif, filter, periode promo (`available_from/until`). Add-on: filter, status,
  katalog `AddonSeeder` (15 add-on, aman diulang). Tagihan: filter lengkap, detail, **tagihan manual** (`CreateManualInvoice`).
- Pendaftaran: ringkasan + setelan lanjutan (paket trial, batas/hari, domain diblokir, email tim, pesan tutup).
  Keamanan: `SecurityChecklist` + daftar admin 2FA. Migrasi `2026_11_06_100000_add_plan_period_and_manual_invoices`.
- 1922+ test SQLite lulus; billing & admin juga lulus di MySQL `gspos_test`.

### Fondasi (Minggu 1–6) — sudah di-commit
- Standar proyek, SPEC, ADR 0001–0010, CI, envelope API, tenant scope fail-closed, permission per role.
- Auth API: login email, device token, login PIN (kunci 15 menit), `/auth/me`, logout.
- Katalog: produk, kategori, grup opsi, ETag `/catalog`.
- Transaksi: shift, `OrderCalculator`, checkout, open bill, split payment, struk, void.
- Laporan: API, widget beranda, menu Penjualan/Shift/Laporan, export Excel.

### Dashboard & situs — sudah di-commit
- Gaya UI seragam: tabel, form, tab di card, filter lipat, URL bersih, font Plus Jakarta Sans.
- Katalog+: favorit, stok minimum/menipis, urutan kasir, status kategori/grup opsi.
- Subdomain `gspos.id` / `app.` / `admin.` / `api.` (ADR 0008); semua URL berbahasa Inggris.
- Onboarding (ADR 0009): daftar mandiri + trial, mode hanya-baca, verifikasi email, checklist +
  template menu, lead → tenant, lupa kata sandi, edisi jual putus + `pos:install`.
- Setelan: Profil outlet, Karyawan (PIN + username/kata sandi untuk kasir web), Metode pembayaran.

### Multi-outlet (ADR 0010, SPEC Q36–Q41) — selesai, di-commit (`be9f86c`)
| Langkah | Isi |
|---|---|
| M2 Data | Migrasi `2026_10_11_100000_add_multi_outlet`: `outlet_user`, `outlet_product` (stok, stok minimum, tersedia per outlet), `tenants.max_outlets`, `outlets.is_active`, `stock_movements.outlet_id`. Kolom stok di `products` & `users.outlet_id` dihapus, data lama dipindahkan |
| M3 Akses | `User::accessibleOutlets()` (owner = permission `outlet.access_all`), `OutletScope` fail-closed (order/shift/device → 404 di outlet lain), `CurrentOutlet` per request (outlet device / pilihan session), token di device outlet lain atau nonaktif → 403 |
| M4 Dashboard | Pengaturan → Outlet (`settings/outlets`: tambah, nonaktifkan, error `OUTLET_LIMIT_REACHED`), Profil outlet per outlet, pemilih outlet di topbar, pilihan outlet di form Karyawan, admin "Maksimal outlet aktif" |
| M5 Stok | `ProductStock` + `Product::atOutlet()`; `AdjustStock`, tandai habis, potong stok checkout, `/catalog`, `PinUsers` per outlet |
| M6 Laporan | Penjualan, Shift, Laporan, Beranda mengikuti outlet topbar; `GET /v1/outlets`; laporan API `?outlet_id=` (id atau `all`) |

Keputusan penting:
- Bisnis baru (SaaS) dibatasi **1 outlet aktif** (`POS_DEFAULT_MAX_OUTLETS`); bisnis hasil migrasi tanpa batas.
- Saat "Semua outlet" dipilih, halaman Produk menampilkan stok **outlet pertama** (ada keterangan di subjudul).
- Outlet tidak pernah dihapus; nonaktif = perangkatnya tidak bisa bertransaksi, laporan tetap ada.
- Dokumentasi sudah diperbarui: SPEC, `docs/api/{auth,settings,product,report,README}.md`, `docs/PROGRESS.md` §1c.

### Menu & metode bayar per outlet (ADR 0011, SPEC Q42–Q45) — selesai, di-commit
| Langkah | Isi |
|---|---|
| P1 Keputusan | Opsi B: katalog bersama, produk dipilih per outlet, **harga tetap sama** (harga per outlet di backlog); metode bayar cukup aktif/nonaktif per outlet; SPEC Q39 diganti |
| P2 Data | `outlet_product.is_listed`, `outlet_option`, `outlet_payment_method`. **Tanpa baris = dijual/tersedia/aktif** (sama dengan stok outlet), jadi data lama tidak disalin |
| P3 Action | `SetProductListing` (lewat `SaveProduct`, hanya outlet aktif yang dipegang user), `SetOptionAvailability`, `SetPaymentMethodAtOutlet` (tunai selalu aktif), `CreateOutlet` `copyMenuFrom` |
| P4 Dashboard | Form produk "Dijual di outlet", badge/filter "Tidak dijual", aksi "Opsi habis" (outlet topbar), metode bayar "Aktif di outlet" + kolom Outlet, tambah outlet "Salin menu & metode bayar dari" |
| P5 API | `/catalog` per outlet perangkat (+ `options[].is_available`, kategori kosong tidak dikirim); checkout/tambah bayar menolak dengan `VALIDATION_ERROR` per field |

Catatan untuk Flutter: field baru `options[].is_available`; kategori aktif tanpa produk di outlet tidak lagi dikirim.

### Beranda & modul Laporan (SPEC Q49–Q52, ADR 0012) — selesai, di-commit
| Bagian | Isi |
|---|---|
| Fondasi | `ReportPeriod` (preset minggu/tahun, granularitas jam/hari/minggu/bulan, batas 31 hari), `ReportFilters` (+ kategori, produk, shift), `ReportContext` (satu paket parameter + batas supervisor), snapshot `order_items.category_name` |
| Dashboard | Beranda & Laporan berbagi `InteractsWithReportFilters` (URL ringkas `?period=&from=&to=&compare=&type=&group=`, `?tab=`); Laporan 12 tab (`ReportTab`), widget per tab, tabel dari data teragregasi |
| Akses | Supervisor masuk panel **hanya Laporan** (tab operasional, maks. 31 hari dipotong di server); Penjualan/Shift/cetak ulang struk butuh `UserRole::hasFullDashboard()`; kasir: rekap shift sendiri via API |
| API | `trend`, `categories`, `options`, `employees`, `shifts`, `shifts/{id}` (+ `/pdf`), `heatmap`, `stock` — izin per endpoint dari `ReportTab` (`docs/api/report.md`) |
| Export | Grup Export: Excel/PDF tab ini atau lengkap, sinkron, batas 50.000/5.000 baris → "Persempit rentang tanggal"; dompdf tanpa PHP/remote/JS |

Catatan: PDF memakai DejaVu Sans sampai file `resources/fonts/PlusJakartaSans-Regular.ttf` & `-Bold.ttf` ditambahkan
(template otomatis memakainya). HPP/margin belum ada: `products.cost_price` sudah ada, tetapi belum di-snapshot ke
`order_items` (lihat rekomendasi R1).

### Perubahan terakhir — sesi 2026-10-11 (semua di-push)
| Commit | Isi |
|---|---|
| `0d12fe4` | Beranda: URL ringkas (`?period=…` hanya nilai bukan bawaan, `$filters` hanya di session), ikon kalender membuka popup, nominal KPI mengecil mengikuti lebar kartu, teks tren singkat ("Naik 12,5%") |
| `d7a1ad5` … `d496469` | Modul Laporan tahap 1–7 (tabel di atas) |
| `42a40c9` | Filter jadi toolbar ringkas (6 field satu baris di layar lebar), filter lanjutan di **panel samping** (badge jumlah + "Hapus filter"), KPI 2 → 4 kolom & lebih rapat |
| `6f42910` | Beranda: "Mulai berjualan" paling atas; **semua form**: input group tanpa garis pemisah ikon/label, DatePicker 32px; jarak antarblok/widget/kartu seragam 16px |
| `8c0fedc` | Tombol "Filter lanjutan" & tab tersegmentasi 32px (standar = tombol Simpan/Batal) |

Bug yang ditemukan & diperbaiki di sesi ini (semua ada test): tanggal rusak di URL membuat halaman error; download PDF
lewat Livewire gagal (kini stream); nomor halaman PDF "1 / 0"; filter lanjutan hilang saat form diisi ulang; nilai
enum tipe order dari form diabaikan; link berparameter sebagian memakai periode yang salah.

Standar UI yang kini berlaku (jaga konsistensinya): tinggi kontrol **32px** (`--control-h`) untuk field, tombol,
dan tab tersegmentasi; ukuran kecil hanya di dalam tabel (paginasi 28px, ikon aksi baris 26px); jarak antarblok 16px;
input group tanpa garis pemisah; tanpa `style=""`/hex di Blade (pakai kelas + token).

---

### Sesi 2026-10-11 sore (SPEC Q55–Q59)
| Commit | Isi |
|---|---|
| `b1df171` | Menu **Kasir** di sidebar dashboard → `/cashier/confirm` (tab baru), masuk ulang dengan kata sandi atau PIN (Q55). PIN hanya di jalur ini; tanpa link lupa kata sandi/PIN |
| `dfdb65c` | Halaman masuk kasir bermerek (`x-cashier.auth`: latar navy, panel fitur, versi di bawah kartu), tab cara masuk di form (tanpa `?mode=`), pilih outlet > 3 = dropdown dengan pencarian, Keluar → kembali ke dashboard |
| `9a9487a` | Pesan kasir web jadi **toast kanan atas** (`PointOfSale::toast()` → event `cashier-toast`); hapus/lanjutkan transaksi tertahan ada pesannya; perbaikan total lama tertinggal setelah Tahan |
| `6a58a6d` | Item sama (produk + opsi) tanpa catatan & diskon item digabung ke satu baris |
| `e05a85c`, `3c18e86` | Semua halaman masuk (kasir, admin, Filament simple page, `gspos.id/login`) memakai field & tombol 40px; halaman lain tetap 32px |
| `8995ca5` | PIN sendiri di **Profil** (wajib kata sandi saat ini, reset kunci PIN, cabut token) |
| `0b9e64a` | **R6** target omzet harian/bulanan per outlet (Profil outlet & `PUT /v1/outlet`) + widget Beranda (Q56) |
| `27040e1` | **R5** ringkasan harian email `pos:daily-summary` (tiap jam, sekali per hari setelah 07.00 waktu outlet; toggle di Profil, bawaan owner) (Q57) |
| `9fedd51` | **R7** Riwayat perubahan (`spatie/laravel-activitylog`, `ActivityLog` per tenant, izin `audit.view`), tautan dari panel Audit; disimpan 365 hari (Q58) |
| `489e114` | **Import produk** dari Excel/CSV dengan template, validasi semua baris dulu, upsert per SKU (Q59) |
| `000d2f0` | **R9** uji E2E Dusk (kasir web, menu Kasir, dashboard) dengan `database/dusk.sqlite` terpisah |

Aturan untuk tim Flutter (repo `gs-point-of-sale-app`, tidak ada di mesin ini):
- **F1** — samakan gabung item keranjang: produk & pilihan opsi sama, tanpa catatan & tanpa diskon item → qty
  ditambah ke baris yang ada; beda opsi/catatan/diskon → baris baru (SPEC Q54).
- **F2** — `GET/PUT /v1/outlet` kini memuat `daily_sales_target` & `monthly_sales_target` (nullable, Q56); opsional
  ditampilkan di setelan outlet aplikasi.

### Program member (ADR 0013, SPEC Q60–Q67, Q75) — M1–M7 selesai 2026-10-11
| Commit | Isi |
|---|---|
| `0dd6186`, `6376280` | **M1** data member (HP +62 unik per bisnis, persetujuan, kode acak, hapus = anonimkan), Pengaturan → Program member (aktif, outlet ikut), Pelanggan → Member, API `/v1/members`, `member_id` di checkout/open bill, kolom cari member di kasir web (Enter/scanner) |
| `55d0f7a` | **M2** ledger poin append-only (FIFO, saldo cache + lock), poin saat order selesai (subtotal setelah diskon, tanpa pajak/service), void menarik poin, `pos:expire-member-points` harian, penyesuaian manual (`member.points_adjust`), API riwayat poin, struk |
| `5eb90f7` | **M3** metode bayar sistem **Poin** + bukti QR dinamis / PIN member (kunci 5×), `RedeemPoints` (kelipatan, minimal, maks. % tagihan, saldo), void mengembalikan poin, **diskon member %** di `OrderCalculator` (`MemberPricing`: urutan stempel → manual → voucher → member, tidak dihitung ke batas diskon kasir) |
| `48fbd66` (M4) | **Halaman member** `gspos.id/m/{kode-bisnis}`: masuk HP + kata sandi, daftar mandiri, QR `KODEMEMBER-123456`, riwayat, profil, ganti kata sandi, hapus akun; kata sandi sementara dari dashboard; scan QR di kasir web memasang member + mengisi bukti |
| `324c98c` (M5) | **Tier** (`member_tiers`, Pengaturan → Program member): belanja 12 bulan bergulir dari order selesai (`TierEvaluator`, saat selesai/void + `pos:evaluate-member-tiers` harian), pengali poin memakai tier sebelum order, diskon tier ditambahkan ke diskon member, snapshot `orders.member_tier`, progres tier di halaman member |
| `23204f3` (M6) | **Kartu stempel** (Pelanggan → Kartu stempel): 1 item = 1 stempel, `stamp_program_ids` di checkout/open bill → item termurah gratis (`stamp_free_total`), saldo dicek ulang saat lunas, void membalik; kasir web tombol kartu, halaman member lingkaran stempel |
| `8af65ee` + berikutnya (M7) | **Voucher** (Pelanggan → Voucher): kode promo (nominal/persen, maks., minimal belanja, kuota total/per member, periode, khusus member/tier/outlet) & voucher ulang tahun (`pos:issue-birthday-vouchers`), `voucher_code`/`voucher_id`, kuota dicek ulang saat lunas, error baru `VOUCHER_INVALID`, `GET /v1/vouchers/check`; **laporan Member** (tab + `GET /reports/members` + export), baris member di ringkasan harian, **import/export** member di menu Member |

Catatan non-obvious: semua potongan member lewat `ResolveMemberPricing` → `MemberPricing` (pilihan kasir di
`BenefitSelection`); benefit dicatat di `AwardOrderBenefits` (voucher juga tanpa member) dan dibalik di
`ReverseOrderBenefits`. Stempel/voucher di open bill: field tidak dikirim = pilihan lama tetap.

### Self-order QR meja + Midtrans (ADR 0014, SPEC Q68–Q73, Q76–Q78) — S1–S4 selesai 2026-10-11
| Commit | Isi |
|---|---|
| `2381693` (S1) | Pengaturan → **Meja & QR** (`dining_tables`, cetak kartu QR dari browser, ganti token), setelan self-order per outlet (aktif, batas waktu bayar, jam layanan, bayar di kasir, sambutan), status order `awaiting_payment`/`expired`, `orders.channel` |
| `5f7c5f5` (S2) | Halaman HP `gspos.id/order/t/{token}` (Livewire: menu, opsi, keranjang, voucher, member dari sesi halaman member, status polling); `self_orders` (order dibuat saat kasir **Terima**); kasir web menu **Pesanan meja** (badge + bunyi, terima/tolak, Belum dibayar → Bayar dengan item terkunci); `pos:expire-self-orders` tiap menit |
| `f357563` (S3) | Pengaturan → **Pembayaran online** (Midtrans owner, key terenkripsi, tes koneksi, URL webhook), metode sistem Online, tombol Bayar online di HP (Snap redirect), webhook `POST /webhooks/midtrans/{tenant}` (tanda tangan + cek ulang API + nominal), `refund_required` |
| (S4) | API `GET /v1/self-orders`, `accept`, `reject`; laporan per kanal (`/reports/channels`); bayar poin dari HP |

Catatan non-obvious: Livewire halaman HP membaca ulang meja dari token di `hydrate()` (request update tidak lewat
middleware route); pembayaran online dicatat atas nama kasir yang menerima & di shift penerima; dibayar setelah
kedaluwarsa tetap diselesaikan, setelah void/lunas = refund manual. Cron `schedule:run` tiap menit wajib di cPanel.

### Stok masuk, opname, transfer (ADR 0015, SPEC Q79–Q83) — I1–I4 selesai 2026-10-12
| Tahap | Isi |
|---|---|
| I4 | Laporan tab **Stok**: kolom stok masuk/opname/transfer & nilai stok; tabel pembelian per supplier & per produk; export; `GET /reports/purchases` |
| I3 | Menu **Stok → Transfer** (≥ 2 outlet): draf → Kirim (stok asal turun) → Terima di tujuan (qty diterima, selisih tercatat) / Batalkan saat dalam perjalanan; akses per sisi outlet; izin `stock.transfer` |
| I2 | Menu **Stok → Stok opname**: lembar hitung per outlet/kategori → isi qty fisik → Posting (selisih vs stok saat posting, nilai selisih dari modal), riwayat `opname`; izin `stock.opname` |
| I1 | Menu **Stok → Stok masuk** (draf → Posting → Batalkan, nomor `{outlet}-SM-{YYMMDD}-{urut}`, supervisor ikut punya menu ini) & **Stok → Supplier**; modal produk rata-rata tertimbang lintas outlet saat posting (`WeightedCost`), riwayat stok `purchase`/`purchase_void`; izin `stock.receive`, `supplier.manage` |

### Profil, menu akun & URL pendek QR (SPEC Q84–Q85) — selesai 2026-10-12
- Menu akun: Profil, Bantuan (`POS_SUPPORT_WHATSAPP`/`POS_SUPPORT_EMAIL`), Keluar; tombol tema di kiri nama.
- Profil: foto (disk `public`, `avatars/{tenant_id}` — perlu `php artisan storage:link` di server), nomor HP, Info akun, Perangkat & sesi (keluar dari perangkat lain).
- Ikon kolom isian 16px (CSS global `components.css`).
- QR meja: `/t/{short_code}` → URL asli; kartu cetak 60 mm baru.

Deploy otomatis: `.github/workflows/deploy.yml` (merge ke `master` → CI → down + backup DB → rsync → migrate/cache → up),
persiapan secrets & branch protection di `docs/deploy.md` (belum diaktifkan di GitHub).

### Installer web (ADR 0016, SPEC Q86) — N1–N4 selesai 2026-10-12
- `/install`: kode instal opsional → syarat → database → aplikasi → akun → proses per langkah (AJAX) → login. `docs/install.md`.
- Sebelum terinstal: middleware `EnsureInstalled` (session/cache file, https mengikuti request), kunci `storage/app/installed.json`.
- Paket zip: tag `v*` → workflow Release (`scripts/release/build-package.sh`, edisi self_hosted & saas).
- Belum: uji di hosting cPanel nyata.

### Shift bersama per perangkat (ADR 0017, SPEC Q87–Q91) — C1–C4 selesai 2026-10-14
| Tahap | Isi |
|---|---|
| C1 | Setelan outlet **Laci kasir** (`cash_float`, `auto_lock_minutes` bawaan 5, `cash_out_approval_limit` bawaan Rp100.000); tutup shift oleh siapa saja yang bertugas di device itu; isian **Disetor** → `closing_float`; buka shift = **carry-over** sisa laci device yang sama (`opening_cash` opsional), koreksi kas awal (izin baru `shift.edit_opening_cash` / PIN approver) |
| C2 | Log operator `shift_operators` (buka shift, login PIN/email di device, buka kunci & pilih outlet kasir web); kasir web tombol **Kunci** + kunci otomatis (JS, tanpa polling) → `/cashier/unlock` pilih nama + PIN; kasir melihat order shift berjalan di device-nya |
| C3 | `cash_movements` (masuk/keluar, alasan tetap, idempotent), `expected_cash` ikut kas masuk/keluar, kas keluar ≤ isi laci, di atas batas → izin baru `shift.cash_out_over_limit` / PIN approver; API `POST /cash-movements`, `GET /shifts/{id}/cash-movements`, `POST /shifts/{id}/opening-cash` |
| C4 | X/Z-report (web, PDF, API) kasir bertugas + kas masuk/keluar + disetor; laporan Shift & export kolom kas masuk/keluar & disetor; panel Audit kartu **Kas keluar** + kas awal dikoreksi; ringkasan harian kolom Disetor; detail shift dashboard; tutup paksa dashboard dengan Disetor |

Catatan non-obvious: shift lama (sebelum ADR 0017) tanpa `closing_float` → shift berikutnya tetap mengetik kas awal;
selisih kas ditanggung bersama (atas nama shift), siapa bertugas jam berapa ada di log operator; seeder demo kini
menyetor penjualan dan meninggalkan modal Rp200.000 (carry-over).

### Retur sebagian (ADR 0018, SPEC Q92–Q94) — selesai 2026-10-14
- `POST /v1/orders/{id}/refunds` + `GET /v1/refundable-orders/{nomor}`; kasir web **Riwayat → Retur transaksi**
  (`/cashier/refunds`) dan tombol **Retur** di detail order. Order selesai ≤ `outlets.refund_max_days` hari (bawaan 30, diatur per outlet), item & qty,
  nilai proporsional (`OrderCalculator::refundShare`), tunai = kas keluar alasan **Retur** di shift berjalan / manual,
  restock opsional, poin pembayaran kembali & poin didapat ditarik, belanja & tier member dikurangi.
- Izin baru `order.refund` (owner, manager, supervisor; kasir PIN). Order yang sudah diretur tidak bisa di-void.
- Laporan: Rincian finansial & export (retur, omzet setelah retur), panel Audit daftar Retur, ringkasan harian, detail
  order dashboard. Laporan per produk tetap bruto.

### Pecah & gabung open bill (ADR 0019, SPEC Q95–Q96) — selesai 2026-10-14
- `POST /v1/orders/{id}/split` (item & qty ke bill baru) dan `POST /v1/orders/{id}/merge` (status baru `merged`);
  kasir web: detail open bill → **Pecah bill** / **Gabung bill**. Hanya open bill tanpa pembayaran, voucher,
  stempel; harga dihitung ulang lewat `SaveOpenBill`. Revisi 2026-10-16: diskon manual ikut dibagi (item proporsional
  qty, order persen di kedua bill / nominal di bill asal; gabung → nominal jumlah potongan), `approved_by` lama ikut lewat
  `OpenBillData::keepApproval` (hanya diisi server).

### Bahan baku, resep, paket (ADR 0020, SPEC Q97–Q99) — selesai 2026-10-14
- `products.kind` (`regular`/`ingredient`/`bundle`) & `unit`; `product_components` (resep/isi paket), `option_components`
  (bahan opsi). Konsumsi & HPP di `App\Services\Stock\StockConsumption` (resep dipecah ≤ 3 tingkat).
- Order selesai memotong stok komponen; void membalik riwayat `sale` order (bukan resep saat ini); retur memakai resep
  saat ini. Bahan tidak dijual (katalog, checkout, barcode, laporan produk kurang laku).
- Dashboard: form produk (Jenis, Satuan, Resep/Isi paket), tab Bahan baku & Paket, kolom Bahan di grup opsi.

### Purchase order (ADR 0021, SPEC Q100–Q101) — selesai 2026-10-14
- Dashboard **Stok → Purchase order** (izin baru `purchase.manage`): draf → Kirim → **Terima barang** (draf Stok masuk dari
  sisa qty, `stock_receipts.purchase_order_id`) → status Diterima sebagian/Diterima dihitung ulang saat stok masuk
  diposting/dibatalkan (`SyncPurchaseOrderReceipts`); Batalkan dengan alasan. Tanpa API & utang supplier.

### Promo otomatis (ADR 0022, SPEC Q102–Q104) — selesai 2026-10-14
- Dashboard **Pelanggan → Promo** (izin baru `promo.manage`). `App\Services\Order\AutomaticPromotions` dipanggil dari
  `PriceOrder::totals` → `OrderCalculator` (parameter `PromoPricing`, setelah gratis stempel, sebelum diskon manual).
- Snapshot `orders.promo_discount` & `orders.promotions`; struk, keranjang kasir web, `POST /v1/orders/quote`,
  `promotions[]` di `/catalog`.

### Kasbon member (ADR 0023, SPEC Q105–Q107) — selesai 2026-10-16
- Pengaturan → Program member → **Kasbon**; metode bayar sistem `receivable`; `ChargeReceivable` (check/handle/reverse)
  dipanggil checkout, tambah pembayaran, void; retur mengurangi kasbon. Bukti QR/PIN member diverifikasi sekali di
  `RedeemPoints::check` (QR sekali pakai).
- Pelunasan `PayReceivable`: API, kasir web **Kasbon**, dashboard Member (tanpa laci). Ledger `receivable_entries`.

### Paket langganan & tagihan SaaS (ADR 0024, SPEC Q108–Q111) — selesai 2026-10-16
- Admin **Pelanggan → Paket** & **Tagihan** (Tandai lunas/Batalkan), detail bisnis → **Atur paket**. Owner
  **Pengaturan → Langganan** (izin baru `billing.manage`): buat tagihan, bayar online (Midtrans vendor) atau transfer.
- `App\Support\Features::enabled()` membatasi menu & mesin per paket; tanpa paket / jual putus = semua fitur.
- Webhook `POST /webhooks/billing/midtrans`; jadwal `pos:billing-reminders` 08.00 WIB. Revisi 2026-10-16: upgrade prorata
  (`InvoiceQuote`, `subscription_invoices.kind`), downgrade/ganti siklus dijadwalkan (`tenants.next_plan_id`, `pos:apply-plan-changes` tiap jam). Env baru `POS_BILLING_*`
  (lihat `.env.example`). URL notifikasi Midtrans vendor: `{APP_URL}/webhooks/billing/midtrans`.

### Paket fitur, kuota, add-on (ADR 0025, SPEC Q112–Q113) — selesai 2026-10-18, di-push
- Admin: detail bisnis (paket, jadwal, tagihan, kuota, add-on), filter Paket, **Buat tagihan** / **Tagih add-on** (termasuk
  paket/add-on khusus, email `InvoiceCreated`), **Atur add-on**, menu **Add-on**, widget *Langganan* di Dasbor (lunas, MRR).
- Fitur & kuota = **master data tabel** `features` / `quotas` (tanpa CRUD, isi lewat migration). `key` dipakai kode.
  Gerbang fitur API `feature:{key}` → `403 FEATURE_NOT_AVAILABLE`; fitur berbayar baru `advanced_reports`, `report_export`,
  `web_cashier`, `online_payment` (paket lama otomatis diberi). Kuota → `409 PLAN_LIMIT_REACHED`; kolom `max_outlets` dihapus
  (`plans.limits`, `tenants.limit_overrides`). `auth/me` → `tenant.features`, `tenant.limits`.
- Paket bawaan: `php artisan db:seed --class=PlanSeeder --force` (sekali di hosting). Tagihan berbaris
  (`subscription_invoice_items`), jenis tagihan `addon`; `pos:apply-plan-changes` juga melepas add-on yang berhenti.
- Flutter: tangani `FEATURE_NOT_AVAILABLE` & `PLAN_LIMIT_REACHED`, sembunyikan menu sesuai `tenant.features`, tampilkan sisa
  kuota dari `tenant.limits`; checkout dengan `member_id`/voucher ditolak bila paket tanpa `member`.

**Backlog 1–4 selesai** (shift bersama, retur, pecah/gabung bill, resep & paket, PO, promo otomatis, kasbon, langganan).

**Berikutnya (lanjut dari sini):** uji installer & deploy di hosting nyata (paket `dist/hosting` 2026-10-21); uji Midtrans
sandbox (S5 owner & billing vendor: QRIS/VA, webhook, email kuitansi) menunggu key & URL publik; pembulatan ke atas di
pratinjau Flutter; integrasi Flutter F9–F13. Belum: utang supplier, cetak PO, laporan per produk dikurangi retur,
WhatsApp ringkasan harian. Revisi bawaan 2026-10-16 selesai: batas retur per outlet, pecah/gabung dengan diskon, upgrade prorata. Flutter **F12**: tampilkan promo (`/catalog`, `/orders/quote`).
Flutter **F10**: retur (`docs/api/order.md`); **F11**: pecah/gabung bill, status order `merged`.

Catatan untuk tim Flutter — **F9** shift bersama (ADR 0017, `docs/api/shift.md`): tombol Kunci + kunci otomatis
(`outlet.auto_lock_minutes` dari `/auth/me`), buka kunci = `POST /auth/pin-login`; buka shift tanpa `opening_cash` bila
`meta.carry_over_cash` ada (koreksi = `opening_cash` + `opening_cash_note` + approver); form tutup dengan `cash_deposited`;
menu kas masuk/keluar (`POST /cash-movements`, tangani `APPROVAL_REQUIRED` + `error.details.limit`); siapa pun yang
login di device boleh menutup shift (tutup paksa hanya untuk device lain).

Catatan untuk tim Flutter (repo aplikasi): **F8** menu Pesanan masuk & Belum dibayar (`docs/api/self-order.md`), status order baru `awaiting_payment`/`expired`, jangan tampilkan metode `online`; **F5** tampilkan `tier` member & `member_tier` di struk; **F6** kartu stempel
(`stamp_cards` di detail member → kirim `stamp_program_ids`; struk `member_stamps`); **F7** voucher (`vouchers` di detail
member → `voucher_id`, kode promo → `GET /v1/vouchers/check` lalu `voucher_code`; tangani `VOUCHER_INVALID`).
**F3** cari/daftar member (`/v1/members`), pasang `member_id`; **F4** metode
bayar `category: points` hanya bila member terpasang, kirim `member_code` (6 digit terakhir hasil scan QR) atau
`member_pin`; tampilkan `member_discount`, `points_earned`, `member_points` di struk. Error baru: `MEMBER_NOT_FOUND`,
`MEMBER_PROGRAM_DISABLED`, `INSUFFICIENT_POINTS`, `INVALID_MEMBER_PROOF`, `MEMBER_PIN_LOCKED`.

Data lokal demo: program member **aktif** di "Kopi Senja (Demo)", member "Rina Kusuma" (0812-3456-7890) dan
"Member Demo" (0812-7777-1234, kata sandi `demomember1`, dibuat lewat halaman member saat uji).

## 2. Belum dikerjakan

| Prioritas | Pekerjaan | Catatan |
|---|---|---|
| 0 | Tindak lanjut modul Laporan | Lihat tabel "Perlu diubah / dilanjutkan" di bawah |
| ✅ | Kasir web (SPEC Q54, W1–W6) | Selesai 2026-10-11: akses per karyawan, login `/cashier`, shift X/Z, layar transaksi, open bill, approval PIN, void, menu Perangkat. Sisa: uji di browser tablet sungguhan & printer thermal; cetak tanpa dialog di luar cakupan |
| ✅ | Minggu 7 — panel admin | Selesai 2026-10-11 (lihat PROGRESS); sisa OWASP ZAP & uji manual di staging |
| 3 | Minggu 8 — deploy | **Deploy:** paket `dist/hosting/` (zip proyek + `gspos-database.sql` dari DB lokal + `PANDUAN-HOSTING.html`) dibangun ulang 2026-10-21; APP_KEY harus sama dengan lokal (data terenkripsi). Sisa: cPanel nyata, uji MySQL/MariaDB hosting, cron `schedule:run`, uji dengan Flutter, pilot |
| ✅ | Import produk dari Excel | Selesai 2026-10-11 (`489e114`, Q59) |
| 2 | Integrasi Flutter | L1 + F1–F2 (repo aplikasi) |
| Ditunda | Aplikasi tablet | Fase tablet/aplikasi kasir (menu Perangkat sudah ada, Q54) |

### Perlu diubah / dilanjutkan (modul Laporan & UI)
| # | Pekerjaan | Kenapa | Catatan |
|---|---|---|---|
| L1 | Integrasi Flutter | Endpoint laporan baru & izin supervisor/kasir belum dipakai aplikasi | `docs/api/report.md`: supervisor ≤ 31 hari (422 di luar itu), kasir `shifts/{id}` + `/pdf` (X/Z-report) |
| L2 | Uji migrasi `category_name` & `unit_cost` di MySQL dengan data besar | Backfill `UPDATE` per kategori; perlu dicek waktu jalannya di hosting | Jalankan di salinan DB produksi sebelum deploy |
| L3 | Font PDF Plus Jakarta Sans | Kini DejaVu Sans | Tambahkan TTF (lisensi OFL) ke `resources/fonts/` — butuh persetujuan pemilik untuk mengunduh — ✅ Selesai 2026-10-11: TTF Regular/Bold + OFL.txt dari github.com/tokotype/PlusJakartaSans, subsetting aktif, cache `storage/fonts` |
| L4 | Uji batas export di hosting | dompdf & Excel sinkron memakai memori/waktu PHP shared hosting | Bila perlu turunkan `ReportExporter::MAX_PDF_ROWS` / `MAX_EXCEL_ROWS` |
| L5 | Total di footer tabel produk/kategori/opsi | Tabel array Filament tidak mendukung summarizer; total kini di deskripsi tabel | Opsional: `contentFooter()` khusus — ✅ Selesai (`2990a64`): baris total `<tfoot>` via `ReportTableWidget::totalRow()` |
| L6 | Seragamkan ukuran tersisa (opsional) | Paginasi 28px, ikon aksi baris 26px, tab garis bawah Penjualan/Shift 39px | Sengaja tidak diubah (standar tabel/navigasi) — tanyakan pemilik — ✅ Selesai: paginasi, aksi baris, tab kartu = 32px (`--control-h`); tinggi baris tabel tetap 37px |
| L7 | Perbarui dokumentasi OpenAPI (Scramble) & `docs/api/README.md` (jumlah endpoint) | 9 endpoint laporan baru | — — ✅ Selesai (`a2a33bd`): 58 endpoint, Scramble tanpa masalah (`scramble:analyze`) |

### Rekomendasi tambahan
| # | Rekomendasi | Manfaat |
|---|---|---|
| R1 | Snapshot `cost_price` ke `order_items` saat checkout → laporan HPP & margin kotor per produk/kategori | Finance: laba kotor akurat walau harga modal berubah (`products.cost_price` sudah ada) — ✅ Snapshot selesai (`2a6a926`, Q53: `order_items.unit_cost`); laporan Laba kotor ✅ (tab + API `/reports/profit`) |
| R2 | Cache agregat laporan (driver `database`, 60 detik, kunci tenant + filter; periode yang sudah lewat lebih lama) — sekaligus menutup T1 | Beranda/Laporan lebih ringan di shared hosting; `daily`/`hourly`/`heatmap` kini mengelompokkan di PHP — ✅ Selesai: `CachedReportService` (60 detik, periode lewat 10×, "Muat ulang" = flush per tenant) |
| R3 | Throttle aksi & endpoint export (mis. 10/menit per user) | Export sinkron cukup berat; cegah penyalahgunaan (DoS ringan) — ✅ Selesai (`bd473df`): `ExportThrottle`, `pos.rate_limits.export` |
| R4 | Wajib email terverifikasi untuk login dashboard supervisor | Supervisor kini bisa login web (Q50); verifikasi (Q46) belum memblokir akses — ✅ Selesai (`ae629f7`) |
| R5 | Ringkasan harian otomatis (email/WhatsApp) lewat cron cPanel `schedule:run` | Owner tahu omzet & anomali (void, selisih kas) tanpa membuka dashboard — ✅ Email selesai (`27040e1`, Q57); WhatsApp belum |
| R6 | Target omzet harian/bulanan per outlet + progres di Beranda | Manager punya patokan, bukan hanya pembanding periode lalu — ✅ Selesai (`0b9e64a`, Q56) |
| R7 | Audit log (`spatie/laravel-activitylog`, Fase 2) terhubung ke panel Audit | Jejak perubahan harga, diskon, void lebih lengkap — ✅ Selesai (`9fedd51`, Q58); void & diskon tetap di panel Audit |
| R8 | Simpan preset filter per user ("Laporan mingguan outlet A") | Mempercepat rekap rutin — ✅ Selesai: grup aksi Preset di Laporan (tabel `report_presets`, maks. 10 per user) |
| R9 | Tabel data pendamping grafik (aksesibilitas `chart.md`) & uji E2E browser untuk alur laporan | Mutu & aksesibilitas — ✅ Tabel pendamping grafik (tren & jam ramai; kategori & metode bayar sudah ada). Uji E2E browser ✅ Dusk (`000d2f0`, `docs/standards/testing.md` §8) |

Utang teknis tersisa (lihat PROGRESS): T1 cache widget beranda, T2 gambar lama tidak terhapus,
T3 cetak struk dari web, T4 Node lokal, T5 rencana PHP 8.3 + Laravel 13, T6 `POS_ADMIN_2FA_REQUIRED=true` di production.

Ide lanjutan multi-outlet (opsional): stok "Semua outlet" sebagai total, transfer stok antar outlet,
filter Karyawan per outlet, test migrasi data multi-outlet di MySQL, harga & nama metode bayar per outlet (ADR 0011, ditunda).

---

## 3. Peringatan saat melanjutkan

- **Jangan** menjalankan `migrate:fresh`, `migrate:refresh`, `migrate:reset`, atau `db:wipe`, termasuk
  dengan `--env=testing`: `.env.testing` tidak ada, jadi perintah itu menghapus `database/database.sqlite`.
  Pada 2026-10-10 data lokal sempat terhapus; data demo sudah diisi ulang dengan `php artisan db:seed`.
- Test memakai SQLite `:memory:` dari `phpunit.xml`; PHPStan butuh `--memory-limit=1G`.
- Akun demo lokal: lihat komentar di `database/seeders/DemoTenantSeeder.php`.

```bash
php artisan test --parallel
./vendor/bin/pint
./vendor/bin/phpstan analyse --memory-limit=1G
```
