# Standar Testing

Framework: **Pest**. Setiap Action dan endpoint baru wajib punya test di PR yang sama.

## 1. Jenis test & lokasi

| Jenis | Lokasi | Menguji | Database |
|---|---|---|---|
| Unit | `tests/Unit/` | Service & Enum murni: `OrderCalculator`, `Money`, `UserRole::allows()` | Tidak |
| Action | `tests/Feature/Actions/{Domain}/` | Aturan bisnis tanpa HTTP | Ya |
| API | `tests/Feature/Api/V1/{Domain}/{Endpoint}Test.php` | Kontrak HTTP: status, envelope, kode error | Ya |
| Filament | `tests/Feature/Filament/{Panel}/` | Resource bisa dibuka, aksi memanggil Action, permission menyembunyikan menu | Ya |

## 2. Lima kasus wajib per endpoint

| # | Kasus | Assert |
|---|---|---|
| 1 | Sukses | Status 200/201, `success: true`, bentuk `data` sesuai Resource, efek di DB |
| 2 | Validasi gagal | 422, `error.code = VALIDATION_ERROR`, `error.details` berisi field yang salah |
| 3 | Permission ditolak | 403 `FORBIDDEN` (atau `APPROVAL_REQUIRED` untuk aksi 🔑) |
| 4 | Isolasi tenant | Data tenant B → 404 `NOT_FOUND`; daftar tidak memuat data tenant B |
| 5 | Request ganda | Endpoint idempotent: 1 baris di DB, respons kedua `idempotent_replay: true` |

Tambahan sesuai fitur: setiap `error.code` bisnis yang bisa dilempar endpoint punya satu test.

## 3. Kasus wajib unit `OrderCalculator`

Diskon item, diskon order (fixed & persen), service charge, pajak exclusive, pajak inclusive,
pembulatan (ke 100, ke 500, tanpa), kombinasi semuanya, nilai nol, dan kasus pembulatan .5.
Gunakan **dataset Pest** dengan angka yang dihitung manual di komentar.

```php
it('menghitung total sesuai urutan SPEC', function (array $input, string $expected) {
    expect(app(OrderCalculator::class)->calculate(...$input)->grandTotal)->toBe($expected);
})->with([
    // subtotal 70.000 − diskon 5.000 = 65.000; service 5% = 3.250; pajak 11% × 68.250 = 7.507,50
    // total 75.757,50 → bulat ke 100 = 75.800
    'service + pajak + pembulatan' => [[...], '75800.00'],
]);
```

## 4. Helper bersama (`tests/Pest.php`)

| Helper | Fungsi |
|---|---|
| `tenant(): Tenant` | Membuat tenant + outlet aktif |
| `actingAsRole(UserRole $role, ?Tenant $tenant = null): User` | User dengan role + token Sanctum + `TenantContext` |
| `apiHeaders(): array` | `Accept`, `X-App-Version` valid |
| `openShift(User $user): Shift` | Shift terbuka untuk device user |
| `assertApiError(TestResponse $r, string $code, int $status)` | Cek envelope error |

```php
it('menolak void oleh kasir tanpa PIN approver', function () {
    $cashier = actingAsRole(UserRole::Cashier);
    $order = Order::factory()->completed()->for(openShift($cashier))->create();

    $response = $this->postJson("/api/v1/orders/{$order->id}/void", ['reason' => 'Salah input'], apiHeaders());

    assertApiError($response, 'APPROVAL_REQUIRED', 403);
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);
});
```

## 5. Database test

- Default: SQLite in-memory (cepat) untuk unit & sebagian besar feature test.
- **Wajib MySQL** untuk test yang bergantung pada `lockForUpdate`, kolom JSON, atau perilaku
  constraint (nomor order, checkout, idempotency). Tandai dengan `->group('mysql')`.
  CI menjalankan grup ini dengan service MySQL 8.
- Pakai `RefreshDatabase` / `LazilyRefreshDatabase`. Data via factory, bukan seeder.

## 6. Penamaan & gaya

- Deskripsi test dalam Bahasa Indonesia, kalimat perilaku: `it('mengembalikan stok saat order di-void')`.
- Satu perilaku per test. Gunakan `describe()` per endpoint/aksi bila file panjang.
- Hindari mock untuk model & Action; mock hanya untuk layanan eksternal (Sentry, storage backup).

## 7. Perintah

```bash
php artisan test                          # semua
php artisan test --filter=Checkout        # satu fitur
php artisan test --group=mysql            # test yang butuh MySQL
php artisan test --coverage --min=80      # cakupan (target Actions & Services ≥ 80%)
```
