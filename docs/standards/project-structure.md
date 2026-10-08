# Standar Struktur Project

Tujuan: siapa pun bisa menebak lokasi file **tanpa mencari**. Strukturnya mengikuti standar Laravel,
dengan satu prinsip tambahan:

> **Kelompokkan per domain di dalam setiap lapisan.**
> Lapisan (Actions, Controllers, Requests, Resources, Filament) menjadi folder level atas, dan
> domain (`Order`, `Shift`, `Product`, ...) menjadi subfolder yang namanya sama di semua lapisan.

## 1. Domain (nama baku)

Gunakan nama domain ini secara konsisten, baik di folder, scope commit, maupun nama file dokumentasi.

| Domain (folder) | Scope commit | Isi |
|---|---|---|
| `Auth` | `auth` | Login, PIN, device, token |
| `Tenant` | `admin` | Tenant, outlet, langganan |
| `Catalog` | `catalog` | Endpoint `/catalog` (gabungan baca) |
| `Product` | `product` | Kategori, produk, grup opsi, stok |
| `Shift` | `shift` | Buka/tutup shift, kas |
| `Order` | `order` | Checkout, open bill, void, struk |
| `Payment` | `payment` | Metode bayar, pembayaran |
| `Report` | `report` | Laporan, export |
| `User` | `auth` | Karyawan & PIN |
| `System` | `admin` | Versi app, pengumuman, status sistem |

## 2. Pohon folder

```
gs-point-of-sale-platform/
├── AGENTS.md                         # Titik masuk: cara bekerja
├── CLAUDE.md                         # Aturan inti (dimuat otomatis oleh Claude Code)
├── README.md                         # Setup lokal singkat
│
├── app/
│   ├── Actions/                      # ★ Logika bisnis. 1 kelas = 1 use case = 1 transaksi
│   │   ├── Auth/                     #   LoginWithPassword, LoginWithPin, RegisterDevice, RevokeDevice
│   │   ├── Order/                    #   CheckoutOrder, SaveOpenBill, AddPayment, VoidOrder
│   │   ├── Product/                  #   CreateProduct, UpdateProduct, AdjustStock, ToggleAvailability
│   │   ├── Shift/                    #   OpenShift, CloseShift, ForceCloseShift
│   │   ├── Tenant/                   #   CreateTenantWithOwner, SuspendTenant
│   │   └── User/                     #   CreateUser, UpdateUserPin, DeactivateUser
│   │
│   ├── Enums/                        # ★ Semua nilai tetap (status, tipe, role, error code)
│   │   ├── ErrorCode.php             #   Kode error resmi (cermin tabel di SPEC)
│   │   ├── UserRole.php              #   Satu-satunya pemetaan role -> permission
│   │   ├── OrderStatus.php  OrderType.php  PaymentCategory.php  ShiftStatus.php ...
│   │
│   ├── Exceptions/
│   │   └── BusinessException.php
│   │
│   ├── Filament/
│   │   ├── Admin/                    # Panel /admin (super admin) — boleh lintas tenant
│   │   │   ├── Pages/                #   SystemHealth, MaintenanceMode, Backups
│   │   │   └── Resources/            #   TenantResource, AdminResource, AppVersionResource, ...
│   │   ├── Dashboard/                # Panel /dashboard (owner/manager) — selalu 1 tenant
│   │   │   ├── Pages/                #   Reports, OutletSettings
│   │   │   ├── Resources/            #   ProductResource, OrderResource, ShiftResource, ...
│   │   │   └── Widgets/              #   SalesTodayWidget, TopProductsWidget, ...
│   │   └── Shared/                   # Komponen dipakai kedua panel (kolom uang, badge status)
│   │       ├── Columns/              #   MoneyColumn
│   │       └── Forms/                #   MoneyInput, PinInput
│   │
│   ├── Http/
│   │   ├── Controllers/Api/V1/       # 1 controller per domain: OrderController, ShiftController
│   │   ├── Middleware/               # AssignRequestId, SetTenantContext, EnsureTenantActive,
│   │   │                             # EnsureDeviceRegistered, CheckAppVersion
│   │   ├── Requests/Api/V1/{Domain}/ # CheckoutRequest, VoidOrderRequest
│   │   └── Resources/Api/V1/         # OrderResource, OrderItemResource, ShiftResource
│   │
│   ├── Models/                       # 1 file per tabel, nama tunggal: Order, OrderItem
│   │   └── Concerns/                 # BelongsToTenant (HasUuids memakai bawaan Laravel)
│   │
│   ├── Policies/                     # Otorisasi per model — memakai permission, bukan role
│   ├── Providers/
│   │   └── Filament/                 # AdminPanelProvider, DashboardPanelProvider
│   ├── Services/                     # Perhitungan tanpa efek samping DB: OrderCalculator,
│   │                                 # OrderNumberGenerator, ReportService
│   └── Support/                      # Helper tanpa logika bisnis: ApiResponse, TenantContext, Money
│
├── bootstrap/app.php                 # Middleware + pemetaan exception -> ApiResponse
├── config/pos.php                    # Setelan POS: pembulatan, batas diskon default, umur token kasir
│
├── database/
│   ├── factories/                    # 1 factory per model, dengan state: ->voided(), ->forTenant()
│   ├── migrations/                   # Urut: sistem → akses → produk → transaksi → pembayaran
│   └── seeders/                      # DemoTenantSeeder, PaymentMethodSeeder, AdminSeeder
│
├── docs/
│   ├── SPEC.md                       # Spesifikasi produk (sumber kebenaran)
│   ├── README.md                     # Indeks dokumentasi
│   ├── standards/                    # Acuan: struktur, coding, API, UI, testing, workflow, docs
│   ├── api/                          # Dokumentasi endpoint per domain (order.md, shift.md, ...)
│   ├── features/                     # Spesifikasi rinci per fitur (opsional, dari template)
│   ├── adr/                          # Architecture Decision Records
│   └── templates/                    # Template dokumen
│
├── lang/id/                          # Pesan validasi & label Bahasa Indonesia
├── resources/
│   ├── css/filament/
│   │   ├── admin/theme.css           # Tema panel /admin
│   │   └── dashboard/theme.css       # Tema panel /dashboard
│   └── views/filament/               # Blade kustom (hanya jika komponen bawaan tidak cukup)
│
├── routes/
│   ├── api.php                       # Prefix /api/v1 + middleware global, memuat api/v1.php
│   ├── api/v1.php                    # Route v1 terautentikasi, dikelompokkan per domain
│   ├── web.php
│   └── console.php                   # Jadwal cron
│
└── tests/
    ├── Feature/
    │   ├── Api/V1/{Domain}/          # 1 file per endpoint: CheckoutTest.php
    │   ├── Actions/{Domain}/         # Test Action langsung (tanpa HTTP)
    │   └── Filament/{Panel}/         # Test Resource/Page Filament
    ├── Unit/                         # Services & Enums: OrderCalculatorTest.php
    └── Pest.php                      # Helper: actingAsRole(), tenant(), apiHeaders()
```

## 3. Aturan penamaan

| Jenis | Pola | Contoh |
|---|---|---|
| Action | `{Verb}{Noun}` (kalimat perintah) | `CheckoutOrder`, `CloseShift` |
| Service | `{Noun}{Role}` | `OrderCalculator`, `OrderNumberGenerator` |
| Controller | `{Domain}Controller` | `OrderController` |
| Method controller | Nama aksi REST/use case | `index`, `show`, `checkout`, `void` |
| Form Request | `{Verb}{Noun}Request` | `CheckoutRequest`, `CloseShiftRequest` |
| API Resource | `{Noun}Resource` | `OrderResource` |
| Enum | Tunggal, PascalCase; case PascalCase; value snake_case | `OrderStatus::Completed = 'completed'` |
| Model | Tunggal | `Order`, `OptionGroup` |
| Tabel | Jamak snake_case | `orders`, `option_groups` |
| Pivot | Gabungan tunggal_jamak sesuai SPEC | `product_option_groups` |
| Kolom FK | `{tunggal}_id` | `shift_id`, `voided_by` (untuk relasi ke `users` bernama peran) |
| Kolom boolean | `is_`/`has_`/kata kerja | `is_active`, `track_stock`, `tax_inclusive` |
| Kolom waktu | `{verb}_at` | `last_seen_at`, `subscription_ends_at` |
| Filament Resource | `{Noun}Resource` | `ProductResource` |
| Widget | `{Noun}Widget` | `SalesTodayWidget` |
| Test | `{Subject}Test.php` | `CheckoutTest.php`, `OrderCalculatorTest.php` |
| Route URL | jamak kebab-case | `/option-groups`, `/payment-methods` |
| Route name | `api.v1.{resource}.{action}` | `api.v1.orders.void` |
| Permission | `{domain}.{aksi}` snake_case | `order.void`, `payment_method.manage` |

## 4. Batas tanggung jawab per lapisan

| Lapisan | Boleh | Tidak boleh |
|---|---|---|
| Controller | Ambil input tervalidasi, panggil 1 Action, kembalikan `ApiResponse` | Query kompleks, `DB::transaction`, hitung nominal |
| Form Request | Validasi bentuk data, `authorize()` dengan `can()` | Cek aturan bisnis (shift terbuka, saldo) |
| Action | Transaksi DB, aturan bisnis, lempar `BusinessException` | Membaca `request()`, mengembalikan `JsonResponse` |
| Service | Perhitungan murni, deterministik | Menyimpan ke DB (kecuali `OrderNumberGenerator` yang memang mengunci sequence) |
| Model | Relasi, cast, scope, accessor sederhana | Aturan bisnis multi-model |
| API Resource | Format output (uang → string, tanggal → ISO UTC) | Query tambahan (pakai eager loading di controller) |
| Filament Resource | Form/tabel/aksi UI, memanggil Action | Logika bisnis langsung di `->action(fn ...)` selain memanggil Action |
| Support | Utilitas tanpa state bisnis | Bergantung pada model bisnis |

## 5. Kapan membuat folder/kelas baru

- **Domain baru** hanya jika muncul di `docs/SPEC.md`. Tambahkan ke tabel §1 dan ke daftar scope commit.
- **Service baru** hanya jika logikanya dipakai ≥ 2 Action atau perlu unit test terpisah.
- **Subfolder di `Support/`** tidak diperlukan sampai isinya > 8 file.
- **Jangan** membuat folder `Helpers/`, `Utils/`, `Repositories/`, atau `Traits/` di root `app/`.
  Gunakan `Support/` atau `Models/Concerns/`.
