# Matriks cakupan test gs.POS

- Dibuat: 2026-10-16 (Fase 1 otomasi uji, commit dasar `eba0b0a`)
- Cara audit: inventaris dari `php artisan route:list --json` (250 route), `app/Actions` (165 Action), `app/Filament`
  (2 panel), `error.code` di SPEC → dicocokkan ke `tests/` lewat grep entry point (URI, nama route, kelas, aksi
  Filament). Status berdasar keberadaan assert, belum menilai kedalaman setiap assert.
- Lima kasus wajib (`docs/standards/testing.md`): **Sukses · Validasi · Izin · Isolasi tenant · Request ganda**.
  ✅ ada · ⚠️ sebagian/tidak langsung · ❌ belum ada · n/a tidak berlaku (mis. GET tanpa efek untuk "ganda").

## 1. Baseline

| Suite | Perintah | Hasil | Durasi |
|---|---|---|---|
| Unit + Feature (SQLite) | `php artisan test --parallel` | 1419 lulus | ±20 dtk |
| Unit + Feature (MySQL 8 `gspos_test`) | `DB_CONNECTION=mysql DB_DATABASE=gspos_test php artisan test` | 1419 lulus (sebelum perbaikan: 3 gagal) | ±230 dtk |
| Browser (Dusk) | `./vendor/bin/pest tests/Browser` | 3 lulus (sebelum perbaikan: 3 gagal) | ±15 dtk |
| Rollback semua migrasi (SQLite sementara) | `migrate` → `migrate:rollback --step=1` berulang | lulus (sebelum perbaikan: 1 gagal) | — |

Temuan baseline (diperbaiki di `eba0b0a`):

| # | Tingkat | Root cause | Perbaikan |
|---|---|---|---|
| B1 | Tinggi | `down()` migrasi `2026_10_21_200000_add_short_code_to_dining_tables` menghapus kolom ber-unique index → SQLite menolak; semua test Dusk (DatabaseMigrations) gagal | `dropUnique` sebelum `dropColumn` |
| B2 | Rendah | Laporan member: urutan tier tanpa pemecah seri → acak di MySQL | `"Tanpa tier"` terakhir, lalu nama |
| B3 | Rendah | Panel Audit: kas keluar dengan `created_at` detik sama → urutan acak | pemecah seri `id` |
| B4 | Test saja | `sum('qty')` MySQL = string | `(int)` di test |

Belum ada test flaky yang terdeteksi (SQLite paralel & MySQL serial sama hasilnya).

## 2. Ringkasan per permukaan

| Permukaan | Jumlah | Lengkap 5 kasus | Celah utama |
|---|---|---|---|
| API v1 | 80 route | checkout, void, refund, cash-movements, pin-login, stock, users POST | izin per role di payments/split/merge/receivable-payments; isolasi laporan lewat HTTP; replay force-close/opening-cash |
| Kasir web | 30 route | login, POS Livewire, open bill | retur ganda, split/merge isolasi & ganda, void ganda, kas awal/kas keluar izin |
| Situs, member, self-order, installer, webhook | ±35 route | login pusat, reset sandi, self-order meja, installer, webhook Midtrans tenant | `self-order.member`, registrasi member tanpa throttle, replay webhook billing |
| Filament dashboard | 28 Resource/Page | Reports, Products, OutletSettings, Devices, DiningTables | isolasi Shift/Employee/PaymentMethod/Promotion; izin Category/OptionGroup; halaman Edit dokumen stok |
| Filament admin | 13 Resource/Page | LogViewer, MaintenanceMode, SecuritySettings | **PlanResource tanpa test**, CancelInvoice, TenantPlanAction |
| Actions | 165 | ±92 langsung, ±67 tidak langsung | 6 tanpa test (lihat §6) |
| `error.code` | 34 (SPEC = enum, dijaga `ErrorCodeTest`) | 27 ✅ | 3 ❌, 4 ⚠️ (lihat §7) |

## 3. Celah terbesar (urut risiko bisnis)

| # | Area | Celah | Risiko |
|---|---|---|---|
| G1 | Uang | Retur kasir web (`cashier.refunds.store`) tanpa test kirim ganda | uang keluar dua kali |
| G2 | Uang | Izin role → 403 belum diuji di `orders/{id}/payments`, `split`, `merge`, `members/{id}/receivable-payments` | regresi middleware izin tidak tertangkap |
| G3 | Data tenant | `merge` (API & web) dengan bill tenant lain; split/merge web tanpa isolasi & replay | pindah item/member lintas tenant |
| G4 | Data tenant | `refundable-orders/{number}` nomor order tenant/outlet lain belum diuji (nomor mudah ditebak) | kebocoran order |
| G5 | Data tenant | `members/{id}/receivables` & `/points` tanpa isolasi; laporan products/payment-methods/hourly/trend/options/heatmap/stock/channels/members & daftar shifts tanpa isolasi lewat HTTP | kebocoran angka & PII |
| G6 | Uang | `shifts/{id}/force-close` tenant lain & ganda; `opening-cash` replay (API & web); izin kas keluar/kas awal web | manipulasi kas laci |
| G7 | Uang | Void dua kali di kasir web; webhook billing settlement dikirim dua kali di level HTTP | stok/poin/kasbon dibalik dua kali; langganan diperpanjang dua kali |
| G8 | Pendapatan | `PlanResource` admin tanpa test; `CancelInvoice`, `TenantPlanAction` tanpa test | harga/fitur paket salah |
| G9 | Klien | `PAYMENT_GATEWAY_ERROR`, `PAYMENT_GATEWAY_NOT_CONFIGURED` tanpa assert kode; `MEMBER_PIN_LOCKED`, `INSUFFICIENT_POINTS` hanya via pesan | Flutter salah bercabang |
| G10 | Katalog/harga | `SetPromotionActive`, `SetVoucherActive`, `SetStampProgramActive`, `SetOptionGroupActive`, `SetDiningTableActive` tanpa test | promo/voucher tetap aktif |
| G11 | Izin produk | `PUT/DELETE products`, `PUT/DELETE categories & option-groups`, `GET users/{id}`, `unlock-pin` tanpa test FORBIDDEN | kasir ubah harga |
| G12 | Spesifikasi | `TABLE_NOT_FOUND` ada di SPEC & enum tetapi tidak pernah dilempar di `app/` | kode mati — perlu keputusan |

## 4. API v1

### Bagian 1 — auth, katalog, produk, pengaturan, shift, laporan, karyawan

| Endpoint | Test | Sukses | Validasi | Izin | Isolasi | Ganda | Catatan |
|---|---|---|---|---|---|---|---|
| `POST /auth/login` | `Api/V1/Auth/LoginTest`, `AppVersionTest` | ✅ | ✅ | ✅ | n/a | ⚠️ | login ulang mengganti token |
| `POST /auth/logout` | `Api/V1/Auth/MeLogoutTest` | ✅ | n/a | ⚠️ | ⚠️ | ❌ | logout ganda belum |
| `GET /auth/me` | `MeLogoutTest` | ✅ | n/a | ✅ | ⚠️ | n/a | isolasi tersirat dari token |
| `POST /auth/pin-login` | `Api/V1/Auth/PinLoginTest` | ✅ | ✅ | ✅ | ✅ | ✅ | lengkap |
| `POST /cash-movements` | `Api/V1/Shift/CashMovementTest` | ✅ | ✅ | ✅ | ✅ | ✅ | lengkap |
| `GET /catalog` | `Product/CatalogTest`, `MultiOutlet/*` | ✅ | n/a | ⚠️ | ⚠️ | n/a | isolasi per outlet saja |
| `POST /categories` | `Product/CategoryTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `PUT /categories/{id}` | `CategoryTest` | ✅ | ❌ | ❌ | ✅ | ❌ | |
| `DELETE /categories/{id}` | `CategoryTest` | ✅ | n/a | ❌ | ✅ | ❌ | |
| `POST /devices` | `Auth/RegisterDeviceTest` | ✅ | ✅ | ✅ | ✅ | ✅ | outlet tenant lain → 422 |
| `GET /devices/{uid}/cashiers` | `Auth/CashiersTest` | ✅ | n/a | ✅ | ⚠️ | n/a | |
| `POST /option-groups` | `Product/OptionGroupTest` | ✅ | ✅ | ✅ | ⚠️ | ✅ | |
| `PUT /option-groups/{id}` | `OptionGroupTest` | ✅ | ❌ | ❌ | ✅ | ❌ | |
| `DELETE /option-groups/{id}` | `OptionGroupTest` | ✅ | n/a | ❌ | ❌ | ❌ | |
| `GET /outlet` | `Settings/OutletTest` | ✅ | n/a | ✅ | ⚠️ | n/a | |
| `PUT /outlet` | `OutletTest`, `SharedShiftReportsTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /outlets` | `MultiOutlet/MultiOutletApiTest` | ✅ | n/a | ✅ | ⚠️ | n/a | |
| `GET /payment-methods` | `Settings/PaymentMethodTest` | ✅ | n/a | ✅ | ❌ | n/a | |
| `PUT /payment-methods/{id}` | `PaymentMethodTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /products` | `Product/ProductTest` | ✅ | ✅ | ❌ | ❌ | n/a | |
| `POST /products` | `ProductTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /products/barcode/{code}` | `ProductTest` | ✅ | n/a | ❌ | ✅ | n/a | |
| `PUT /products/{id}` | `ProductTest` | ✅ | ✅ | ❌ | ✅ | ⚠️ | kasir FORBIDDEN belum |
| `DELETE /products/{id}` | `ProductTest` | ✅ | n/a | ❌ | ✅ | ❌ | |
| `PATCH /products/{id}/availability` | `Product/StockAndAvailabilityTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `POST /products/{id}/stock` | `StockAndAvailabilityTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /reports/summary`, `/dashboard` | `Report/ReportTest` | ✅ | ✅ | ✅ | ✅ | n/a | |
| `GET /reports/audit` | `ReportTest` | ✅ | ⚠️ | ✅ | ✅ | n/a | |
| `GET /reports/categories`, `/employees`, `/profit` | `ReportModuleApiTest`, `ProfitReportTest` | ✅ | ⚠️ | ⚠️–✅ | ✅ | n/a | employees: role ditolak belum |
| `GET /reports/products`, `/payment-methods` | `ReportTest` | ✅ | ⚠️ | ⚠️ | ❌ | n/a | |
| `GET /reports/hourly`, `/heatmap`, `/options`, `/stock`, `/trend` | `ReportModuleApiTest` | ✅ | ⚠️ | ✅ | ❌ | n/a | |
| `GET /reports/channels` | `SelfOrder/SelfOrderApiTest` | ✅ | ⚠️ | ✅ | ❌ | n/a | |
| `GET /reports/members` | `Member/MemberReportTest` | ✅ | ⚠️ | ✅ | ❌ | n/a | PII |
| `GET /reports/purchases` | `Stock/StockReportTest` | ✅ | ⚠️ | ✅ | ⚠️ | n/a | isolasi di service |
| `GET /reports/shifts` | `ReportModuleApiTest` | ✅ | ⚠️ | ✅ | ❌ | n/a | |
| `GET /reports/shifts/{id}` | `ReportModuleApiTest`, `SharedShiftReportsTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `GET /reports/shifts/{id}/pdf` | `ReportExportTest`, `SharedShiftReportsTest` | ✅ | n/a | ✅ | ❌ | n/a | |
| `POST /shifts` | `Shift/ShiftTest`, `SharedShiftTest` | ✅ | ✅ | ✅ | ⚠️ | ✅ | |
| `GET /shifts/current` | `ShiftTest` | ✅ | n/a | ❌ | ❌ | n/a | |
| `GET /shifts/{id}/cash-movements` | `CashMovementTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `POST /shifts/{id}/close` | `ShiftTest`, `SharedShiftTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `POST /shifts/{id}/force-close` | `ShiftTest` | ✅ | ✅ | ✅ | ❌ | ❌ | |
| `POST /shifts/{id}/opening-cash` | `SharedShiftTest` | ✅ | ✅ | ✅ | ✅ | ❌ | |
| `GET /shifts/{id}/summary` | `ShiftTest`, `SharedShiftTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `GET /users` | `Settings/EmployeeTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `POST /users` | `EmployeeTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /users/{id}` | `EmployeeTest` | ✅ | n/a | ❌ | ❌ | n/a | |
| `PUT /users/{id}` | `EmployeeTest` | ✅ | ✅ | ✅ | ✅ | ⚠️ | |
| `POST /users/{id}/unlock-pin` | `EmployeeTest` | ✅ | n/a | ❌ | ❌ | ⚠️ | |

### Bagian 2 — order, pembayaran, member, self-order

| Endpoint | Test | Sukses | Validasi | Izin | Isolasi | Ganda | Catatan |
|---|---|---|---|---|---|---|---|
| `POST /checkout` | `Order/CheckoutTest`, `Security/GspoSec001…`, `GspoBug00x` | ✅ | ✅ | ✅ | ✅ | ✅ | paling lengkap |
| `POST /orders/quote` | `Order/AutomaticPromotionTest` | ✅ | ✅ | ❌ | ❌ | n/a | |
| `GET /orders` | `Order/OrderQueryTest` | ✅ | ✅ | ⚠️ | ✅ | n/a | |
| `PUT /orders/{id}` | `Order/OpenBillTest`, `GspoSec011`, `OrderContractTest` | ✅ | ✅ | ✅ | ✅ | ✅ | upsert 201/200, tanpa `idempotent_replay` (sesuai kontrak) |
| `GET /orders/{id}` | `OrderQueryTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `POST /orders/{id}/merge` | `Order/SplitMergeOpenBillTest` | ✅ | ✅ | ❌ | ⚠️ | ✅ | tenant lain belum |
| `POST /orders/{id}/payments` | `OpenBillTest`, `GspoSec011` | ✅ | ✅ | ⚠️ | ✅ | ✅ | |
| `GET /orders/{id}/receipt` | `OrderQueryTest` | ✅ | n/a | ❌ | ✅ | n/a | policy `reprint` belum via API |
| `POST /orders/{id}/refunds` | `Order/RefundOrderTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `POST /orders/{id}/split` | `SplitMergeOpenBillTest` | ✅ | ✅ | ❌ | ✅ | ✅ | |
| `POST /orders/{id}/void` | `Order/VoidOrderTest`, `GspoSec002/003/011` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `GET /refundable-orders/{number}` | `RefundOrderTest` | ✅ | n/a | ❌ | ⚠️ | n/a | |
| `GET /self-orders` | `SelfOrder/SelfOrderApiTest` | ✅ | n/a | ❌ | ✅ | n/a | |
| `POST /self-orders/{id}/accept` | `SelfOrderApiTest` | ✅ | n/a | ❌ | ✅ | ⚠️ | kedua → 409 |
| `POST /self-orders/{id}/reject` | `SelfOrderApiTest` | ✅ | ✅ | ❌ | ❌ | ❌ | |
| `GET /system/status` | `Auth/SystemStatusTest` | ✅ | n/a | n/a | n/a | n/a | publik |
| `GET /vouchers/check` | `Member/MemberVoucherTest` | ✅ | ⚠️ | ❌ | ⚠️ | n/a | |
| `GET /members` | `Member/MemberApiTest` | ✅ | ❌ | ❌ | ✅ | n/a | |
| `POST /members` | `MemberApiTest` | ✅ | ✅ | ⚠️ | ✅ | ✅ | |
| `GET /members/{id}` | `MemberApiTest` | ✅ | n/a | ⚠️ | ✅ | n/a | |
| `GET /members/{id}/points` | `Member/MemberPointsTest` | ✅ | n/a | ❌ | ❌ | n/a | |
| `GET /members/{id}/receivables` | `Member/CustomerCreditTest` | ✅ | n/a | ❌ | ❌ | n/a | data keuangan |
| `POST /members/{id}/receivable-payments` | `CustomerCreditTest` | ✅ | ✅ | ❌ | ✅ | ✅ | |

## 5. Web (kasir web, situs, member, self-order, installer, webhook)

| Route | Test | Sukses | Validasi | Izin | Isolasi | Ganda | Catatan |
|---|---|---|---|---|---|---|---|
| `cashier.login(.store)` | `Cashier/WebCashierLoginTest`, `WebCashierSecurityTest` | ✅ | ✅ | ✅ | ✅ | n/a | throttle |
| `cashier.confirm(.store)` | `WebCashierConfirmTest` | ✅ | ✅ | ✅ | ✅ | n/a | |
| `cashier.logout` | `WebCashierLoginTest` | ✅ | n/a | ⚠️ | n/a | n/a | |
| `cashier.outlet(.store)` | `WebCashierLoginTest` | ✅ | ✅ | ✅ | ✅ | n/a | |
| `cashier.home` (Livewire POS) | `PointOfSaleTest`, `WebCashierBillVoidTest`, `WebCashierMemberTest`, `HoldTransactionTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `cashier.lock` / `unlock` | `WebCashierSharedShiftTest` | ✅ | ✅ | ✅ | ✅ | n/a | antar-outlet |
| `cashier.shift(.store)` | `WebCashierShiftTest` | ✅ | ✅ | ⚠️ | ✅ | ✅ | |
| `cashier.shift.close` | `WebCashierShiftTest`, `WebCashierSharedShiftTest` | ✅ | ✅ | ✅ | n/a | ⚠️ | |
| `cashier.shift.cash-movements.store` | `WebCashierSharedShiftTest` | ✅ | ✅ | ⚠️ | ❌ | ✅ | |
| `cashier.shift.opening-cash` | `WebCashierSharedShiftTest` | ✅ | ✅ | ❌ | ❌ | ❌ | |
| `cashier.shifts.show(.pdf)` | `WebCashierShiftTest` | ✅ | n/a | ✅ | ✅ | n/a | |
| `cashier.orders` | `WebCashierShiftTest` | ✅ | n/a | ⚠️ | ❌ | n/a | |
| `cashier.orders.show` / `.receipt` | `WebCashierBillVoidTest`, `PointOfSaleTest` | ✅ | n/a | ⚠️ | ✅ | n/a | |
| `cashier.orders.void` | `WebCashierBillVoidTest`, `GspoSec002/003/011` | ✅ | ✅ | ✅ | ✅ | ❌ | |
| `cashier.orders.split` / `.merge` | `WebCashierSplitMergeTest` | ✅ | ⚠️ | ❌ | ❌ | ❌ | |
| `cashier.refunds.*` | `WebCashierRefundTest` | ✅ | ✅ | ✅ | ✅ | ❌ | |
| `cashier.receivables(.pay)` | `Member/CustomerCreditTest` | ✅ | ⚠️ | ⚠️ | ⚠️ | ⚠️ | hanya happy path web |
| `cashier.self-orders` | `SelfOrder/SelfOrderPagesTest`, `SelfOrderFlowTest` | ✅ | n/a | ⚠️ | ⚠️ | ✅ | |
| `landing`, `contact` | `Site/LandingTest` | ✅ | ✅ | n/a | n/a | ✅ | honeypot, throttle |
| `signup(.store)`, `verification.verify` | `Site/SignupTest` | ✅ | ✅ | ✅ | n/a | ✅ | |
| `login(.store)`, `dashboard.login.ticket` | `Routing/CentralLoginTest`, `GspoSec005/010` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `password.*` | `Site/PasswordResetTest` | ✅ | ✅ | ✅ | n/a | ✅ | |
| `docs.test-scenarios` | `Site/TestScenarioPageTest` | ✅ | n/a | ✅ | n/a | n/a | |
| `member.login`, `member.logout` | `Member/MemberPortalTest` | ✅ | ✅ | ✅ | ✅ | n/a | |
| `member.register(.store)` | `MemberPortalTest` | ✅ | ✅ | n/a | ✅ | ⚠️ | tanpa throttle/honeypot |
| `member.home/history/profile/password/code` | `MemberPortalTest` | ✅ | ✅ | ✅ | ⚠️ | n/a | |
| `member.destroy` | `MemberPortalTest` | ✅ | ✅ | ✅ | n/a | n/a | |
| `self-order.table` | `SelfOrderPagesTest`, `SelfOrderFlowTest`, `DiningTableTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `self-order.member` | — | ⚠️ | n/a | ❌ | ❌ | n/a | tidak pernah di-hit langsung |
| `self-order.short` | `Filament/Dashboard/ProfileAndAccountMenuTest` | ✅ | n/a | n/a | ✅ | n/a | |
| `install.*` | `Installer/WebInstallerTest` | ✅ | ✅ | ✅ | n/a | ✅ | |
| `webhooks.midtrans` (tenant) | `SelfOrder/OnlinePaymentTest` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `webhooks.billing` | `Billing/SubscriptionBillingTest` | ✅ | ✅ | ✅ | n/a | ⚠️ | replay HTTP belum |

### E2E browser (Dusk)

| File | Skenario |
|---|---|
| `tests/Browser/WebCashierFlowTest.php` | kasir login → buka shift → jual tunai → kembalian & struk; owner masuk Kasir dari dashboard (kata sandi) → keluar |
| `tests/Browser/DashboardReportsTest.php` | owner login → Beranda → Laporan |
| Belum | signup, portal member, self-order HP, retur, void PIN, pecah/gabung, tutup shift, installer, admin |

## 6. Actions

±92 diuji langsung, ±67 tidak langsung (lewat endpoint/halaman/command), **6 tanpa test**:

| Action | Dipanggil dari | Risiko |
|---|---|---|
| `Billing/CancelInvoice` | admin Tagihan → Batalkan | status tagihan |
| `Promotion/SetPromotionActive` | toggle promo | harga checkout |
| `Member/SetVoucherActive` | toggle voucher | diskon |
| `Member/SetStampProgramActive` | toggle kartu stempel | item gratis |
| `Product/SetOptionGroupActive` | toggle grup opsi | katalog |
| `SelfOrder/SetDiningTableActive` | toggle meja | meja QR |

Tidak langsung tetapi berisiko tinggi (perlu test terfokus): `Order/AllocatePayments` & `PaymentIds` (multi-payment,
kembalian), `Payment/HandleMidtransNotification` & `ConfirmOnlinePayment`, `Billing/HandleBillingNotification`,
`Member/ChargeReceivable` & `PayReceivable`, `Member/AwardOrderBenefits` / `ReverseOrderBenefits` / `RedeemPoints`,
`Product/AdjustStock`, `Stock/SyncPurchaseOrderReceipts`, `Tenant/ChangeTenantStatus` (jalur `activate`),
`Member/SetMemberActive` (jalur `activate`).

| Folder | Langsung | Tidak langsung (contoh) |
|---|---|---|
| Admin | ManageAdmin, ManageBackups, SetMaintenanceMode | CreateAdmin (command), ClearLogFile, ManageFailedJobs, SaveAnnouncement, SaveAppVersion, SetAdminTwoFactorRequirement, UpdateOnboardingSettings |
| Auth | IssueUserToken, DashboardLoginTicket, SendOwnerInvitation, VerifyPin | LoginWithPassword, LoginWithPin, Logout, PinUsers, PinApprovers, RegisterDevice, ResetPasswordWithToken, VerifyApproval |
| Billing | ApplyScheduledPlanChanges, CreateInvoice, MarkInvoicePaid, SavePlan, SendBillingReminders, SetTenantPlan, StartInvoicePayment | HandleBillingNotification |
| Cashier | RegisterWebDevice | LoginWebCashier, ConfirmWebCashierUser, UnlockWebCashier |
| Catalog/Device/Install | ApplyMenuTemplate, GetCatalog, SetDeviceRevoked | RenameDevice, CreateInitialAccounts, FinishInstallation, RunPendingMigrations, WriteInstallEnvironment |
| Member | AdjustMemberPoints, ExpireMemberPoints, AnonymizeMember, ChangeMemberPassword, EvaluateMemberTiers, SaveMemberTiers, ImportMembers, IssueBirthdayVouchers, UpdateBirthdayVoucher, SaveVoucher, SaveStampProgram, SetMemberCreditLimit, UpdateCreditProgram, SetMemberPin, UpdateMemberProgram | PayReceivable, ChargeReceivable, RedeemPoints, VerifyMemberProof, AwardOrderBenefits, ReverseOrderBenefits, ResolveMemberPricing, ResolveOrderMember, RegisterMemberAccount, SaveMember, SetMemberActive |
| Order | CheckoutOrder, VoidOrder, AddPayment, SaveOpenBill | RefundOrder, RefundableOrders, SplitOpenBill, MergeOpenBills, QuoteOrder, VisibleOrders, AllocatePayments, PaymentIds, OrderWriter, PriceOrder, ResolveOrderLines |
| Outlet/Payment | CreateOutlet, SetOutletActive, CreateDefaultPaymentMethods, SetPaymentMethodAtOutlet, StartOnlinePayment, TestPaymentGateway, UpdatePaymentGateway | UpdateOutletSettings, HandleMidtransNotification, ConfirmOnlinePayment, UpdatePaymentMethod |
| Product/Promotion | ImportProducts, SaveProduct, SetOptionAvailability, SetProductAvailability, SavePromotion | AdjustStock, SaveCategory, DeleteCategory, SetCategoryActive, SaveOptionGroup, DeleteOptionGroup, DeleteProduct, SetProductFavorite, SetProductListing |
| Report/Sales | ManageReportPresets, SendDailySummaries | CreateSalesLead, UpdateSalesLead, CreateTenantFromLead |
| SelfOrder | AcceptSelfOrder, SubmitSelfOrder, ExpireSelfOrders, RejectSelfOrder, UpdateSelfOrderSettings, PayWithPointsFromPhone, RegenerateTableQr, SaveDiningTable | — |
| Shift | CloseShift, CorrectOpeningCash, RecordCashMovement, RecordShiftOperator | OpenShift |
| Stock | semua PO, penerimaan, supplier, transfer, opname | SyncPurchaseOrderReceipts |
| Tenant/User | CreateTenantWithOwner, LogoutOtherSessions, UpdateOwnAvatar, SaveEmployee, SendEmployeeVerification, UnlockEmployeePin, UpdateOwnProfile | RegisterTenant, UpdateTenant, ChangeTenantStatus, EnsureOwnerEmailVerified, SetEmployeeActive |

## 7. Filament

### Dashboard

| Resource/Page | Test | Tampil | Simpan | Izin | Isolasi | Catatan |
|---|---|---|---|---|---|---|
| Home | `HomeDashboardTest`, `SetupChecklistTest`, `SalesTargetTest` | ✅ | ✅ | ✅ | ✅ | |
| Reports | `ReportsPageTest`, `ReportsDashboardTest`, `ReportExportTest` | ✅ | ✅ | ✅ | ✅ | |
| OutletSettings | `OutletSettingsTest`, `MultiOutletDashboardTest` | ✅ | ✅ | ✅ | ✅ | |
| SubscriptionSettings | `Billing/SubscriptionBillingTest` | ✅ | ✅ | ✅ | ❌ | |
| PaymentGatewaySettings | `SelfOrder/OnlinePaymentTest` | ✅ | ✅ | ✅ | ⚠️ | |
| MemberProgramSettings | `MemberDashboardTest`, `MemberTierTest`, `MemberVoucherTest` | ✅ | ✅ | ✅ | ✅ | |
| EditProfile | `ProfileAndAccountMenuTest`, `GspoSec012` | ✅ | ✅ | n/a | ✅ | |
| ActivityLogResource | `ActivityLogTest` | ✅ | n/a | ✅ | ✅ | |
| CategoryResource | `CategoryAndOptionGroupResourceTest` | ✅ | ✅ | ❌ | ✅ | |
| OptionGroupResource | `CategoryAndOptionGroupResourceTest` | ✅ | ✅ | ❌ | ✅ | edit record tenant lain belum |
| DeviceResource | `DeviceResourceTest` | ✅ | ✅ | ✅ | ✅ | |
| DiningTableResource | `SelfOrder/DiningTableTest` | ✅ | ✅ | ✅ | ✅ | |
| EmployeeResource | `EmployeeResourceTest`, `EmployeeEmailVerificationTest` | ⚠️ | ✅ | ✅ | ❌ | |
| MemberResource | `MemberDashboardTest`, `MemberImportExportTest` | ✅ | ✅ | ✅ | ✅ | |
| OrderResource | `ReportsDashboardTest`, `ReceiptReprintTest` | ✅ | ✅ | ✅ | ⚠️ | ViewOrder tenant lain belum |
| OutletResource | `MultiOutletDashboardTest` | ✅ | ✅ | ✅ | ✅ | |
| PaymentMethodResource | `PaymentMethodResourceTest` | ✅ | ✅ | ✅ | ❌ | |
| ProductResource | `ProductResourceTest`, `ReadOnlyTenantTest` | ✅ | ✅ | ✅ | ✅ | |
| PromotionResource | `AutomaticPromotionTest` | ✅ | ✅ | ⚠️ | ❌ | |
| PurchaseOrderResource | `Stock/PurchaseOrderTest` | ✅ | ✅ | ✅ | ⚠️ | Edit/List belum |
| ShiftResource | `ReportsDashboardTest`, `SharedShiftReportsTest` | ✅ | ✅ | ✅ | ❌ | |
| StampProgramResource | `MemberStampTest` | ✅ | ✅ | ✅ | ⚠️ | |
| StockOpnameResource | `Stock/StockOpnameTest` | ⚠️ | ✅ | ✅ | ⚠️ | View belum |
| StockReceiptResource | `Stock/StockReceiptTest` | ✅ | ✅ | ✅ | ⚠️ | Edit/List belum |
| StockTransferResource | `Stock/StockTransferTest` | ⚠️ | ✅ | ✅ | ⚠️ | Edit/List belum |
| SupplierResource | `StockReceiptTest` | ⚠️ | ✅ | ✅ | ⚠️ | |
| VoucherResource | `MemberVoucherTest` | ✅ | ✅ | ✅ | ⚠️ | |

Lintas panel: `DashboardAccessTest`, `TableConventionsTest`, `PaginationTest`.

### Admin

| Resource/Page | Test | Tampil | Simpan | Izin | Catatan |
|---|---|---|---|---|---|
| TenantResource | `Admin/TenantResourceTest`, `SelfHostedEditionTest` | ✅ | ✅ | ⚠️ | TenantPlanAction belum |
| AdminResource | `AdminResourceTest` | ⚠️ | ✅ | ✅ | |
| AnnouncementResource | `AnnouncementResourceTest` | ✅ | ✅ | ⚠️ | |
| AppVersionResource | `AppVersionResourceTest` | ⚠️ | ✅ | ✅ | |
| **PlanResource** | — | ❌ | ❌ | ❌ | tanpa test |
| SalesLeadResource | `SalesLeadResourceTest` | ✅ | ✅ | ✅ | |
| SubscriptionInvoiceResource | `SubscriptionBillingTest` | ✅ | ✅ | ⚠️ | Batalkan belum |
| BackupManager, LogViewer, MaintenanceMode, OnboardingSettings, QueueMonitor, SecuritySettings | `Admin/*Test` | ✅ | ✅ | ✅/⚠️ | |

Panel: `AdminPanelAccessTest` (tamu, akun tenant, 2FA).

## 8. `error.code` resmi (34 kode, SPEC = `App\Enums\ErrorCode`, dijaga `tests/Unit/ErrorCodeTest.php`)

| Kode | HTTP | Status | Catatan |
|---|---|---|---|
| UNAUTHENTICATED, INVALID_PIN, DEVICE_NOT_REGISTERED, FORBIDDEN, TENANT_SUSPENDED, SUBSCRIPTION_EXPIRED | 401/403 | ✅ | |
| EMAIL_NOT_VERIFIED | 403 | ⚠️ | hanya checkout |
| APPROVAL_REQUIRED, SELF_APPROVAL_NOT_ALLOWED, NOT_FOUND, MEMBER_NOT_FOUND | 403/404 | ✅ | |
| **TABLE_NOT_FOUND** | 404 | ❌ | tidak pernah dilempar di `app/` |
| SHIFT_NOT_OPEN, ORDER_ALREADY_CLOSED, ORDER_NOT_PENDING, ORDER_EXPIRED | 409 | ✅ | |
| LAST_OWNER_REQUIRED, OUTLET_LIMIT_REACHED | 409 | ⚠️ | satu jalur |
| VALIDATION_ERROR, MEMBER_PROGRAM_DISABLED, INVALID_MEMBER_PROOF, SELF_ORDER_DISABLED, VOUCHER_INVALID, PAYMENT_EXCEEDS_BALANCE, DISCOUNT_OVER_LIMIT | 422 | ✅ | |
| INSUFFICIENT_POINTS | 422 | ⚠️ | assert pesan, bukan kode |
| **PAYMENT_GATEWAY_NOT_CONFIGURED** | 422 | ❌ | |
| PIN_LOCKED, APP_UPDATE_REQUIRED, TOO_MANY_REQUESTS, SERVER_ERROR, MAINTENANCE | 423–503 | ✅ | |
| MEMBER_PIN_LOCKED | 423 | ⚠️ | assert pesan; `retry_after` belum |
| **PAYMENT_GATEWAY_ERROR** | 502 | ❌ | |

## 9. Status setelah Fase 2 (kontrak API & uang kasir web)

109 test baru (SQLite & MySQL hijau, tanpa skip), **tanpa bug produksi ditemukan**:

| File | Test | Menutup |
|---|---|---|
| `tests/Feature/Api/V1/Order/OrderContractTest.php` | 26 | G2, G3 (API), G4, receipt `reprint`, `vouchers/check`, self-order accept/reject |
| `tests/Feature/Api/V1/Shift/ShiftContractTest.php` | 8 | G6 (force-close tenant lain & ganda → replay, opening-cash ganda, `shifts/current`) |
| `tests/Feature/Api/V1/Report/ReportIsolationTest.php` | 17 | G5 (10 endpoint laporan + PDF shift tenant lain, role kasir ditolak) |
| `tests/Feature/Api/V1/Product/CatalogContractTest.php` | 15 | G11 (produk, kategori, grup opsi), isolasi daftar produk/metode bayar/katalog |
| `tests/Feature/Api/V1/Settings/EmployeeContractTest.php` | 9 | G11 (`users/{id}`, `unlock-pin`), logout ganda |
| `tests/Feature/Member/MemberContractTest.php` | 10 | G5 (poin & kasbon member tenant lain), validasi `GET /members` |
| `tests/Feature/Api/ErrorCodeContractTest.php` | 9 | G9 (`MEMBER_PIN_LOCKED` + `retry_after`, `INSUFFICIENT_POINTS`, `EMAIL_NOT_VERIFIED` di payments, `PAYMENT_GATEWAY_*`, `OUTLET_LIMIT_REACHED` jalur kedua) |
| `tests/Feature/Cashier/WebCashierMoneyContractTest.php` | 15 | G1 (retur ganda), G3 (web), G6 (web), G7 (void ganda), kasbon web |

Catatan kontrak yang terungkap (bukan bug, sudah didokumentasikan di test):
- Semua role tenant memegang `order.create`, `order.reprint`, `self_order.handle`, `member.view`; "izin ditolak" untuk
  endpoint itu diuji lewat token perangkat tanpa user (401) dan tenant hanya-baca (403 `SUBSCRIPTION_EXPIRED` / `FORBIDDEN`).
- `GET /products` tidak memeriksa izin (semua role boleh membaca).
- Kasir web: `BusinessException` menjadi error sesi `cashier` (redirect), bukan status HTTP; hanya `findOrFail` → 404.
- `LAST_OWNER_REQUIRED` memang hanya punya satu jalur (turunkan role owner terakhir).
- Kosmetik: `CorrectOpeningCash` tetap menulis log info saat kiriman ulang tanpa perubahan.

Sisa: G7 (replay webhook billing di level HTTP), G8 (PlanResource, CancelInvoice, TenantPlanAction), G10 (toggle aktif),
G12 (`TABLE_NOT_FOUND` — menunggu keputusan), isolasi Filament (Shift/Employee/PaymentMethod/Promotion) → Fase 3–5.

## 10. Rencana fase berikutnya

- **Fase 2 (kontrak API):** tutup G2, G4–G6, G9, G11 dan kolom ❌ di §4.
- **Fase 3 (skenario lintas modul):** `tests/Feature/Scenarios/` — hari normal, void & approval, diskon, multi-outlet,
  stok, member, self-order, isolasi tenant menyeluruh, onboarding & edisi.
- **Fase 4 (Dusk):** alur di §5 "Belum".
- **Fase 5 (keamanan):** matriks role × permission, input berbahaya, jumlah query.
- **Fase 6:** `scripts/test-all.sh`, `composer test:all`, CI terpisah.
