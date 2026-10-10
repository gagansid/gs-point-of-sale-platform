# Kode Error

Daftar resmi ada di `docs/SPEC.md` → *Daftar `error.code` resmi*. File ini menjelaskan **cara memakainya**.
Di kode, daftar tersebut dicerminkan oleh enum `App\Enums\ErrorCode` (value = kode, `status()` = HTTP).

## 1. Daftar & sumber

| HTTP | Kode | Dilempar oleh |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Framework (`AuthenticationException`) |
| 401 | `INVALID_PIN` | Action `LoginWithPin`, `VerifyApproval` |
| 403 | `DEVICE_NOT_REGISTERED` | Middleware `EnsureDeviceRegistered`, Action `LoginWithPin` |
| 403 | `FORBIDDEN` | Framework (`AuthorizationException`) — dari `can()` / Policy / FormRequest |
| 403 | `TENANT_SUSPENDED` | Middleware `EnsureTenantActive` (tenant ditangguhkan) |
| 403 | `SUBSCRIPTION_EXPIRED` | `EnsureTenantActive` (request yang mengubah data saat trial/langganan habis) & `BelongsToTenant` (pengaman model) — ADR 0009 |
| 403 | `EMAIL_NOT_VERIFIED` | Checkout/pembayaran sebelum email owner diverifikasi — ADR 0009 |
| 403 | `APPROVAL_REQUIRED` | Action (void/diskon tanpa permission & tanpa PIN approver) |
| 403 | `SELF_APPROVAL_NOT_ALLOWED` | Action `VerifyApproval` |
| 404 | `NOT_FOUND` | Framework (`ModelNotFoundException`) — termasuk data tenant lain |
| 409 | `SHIFT_NOT_OPEN` | Action order & shift |
| 409 | `ORDER_ALREADY_CLOSED` | Action `SaveOpenBill`, `AddPayment`, `VoidOrder` |
| 409 | `LAST_OWNER_REQUIRED` | Action `UpdateUser`, `DeactivateUser` |
| 422 | `VALIDATION_ERROR` | Framework (`ValidationException`) — dari FormRequest |
| 422 | `PAYMENT_EXCEEDS_BALANCE` | Action `CheckoutOrder`, `AddPayment` |
| 422 | `DISCOUNT_OVER_LIMIT` | Action `CheckoutOrder`, `SaveOpenBill` |
| 423 | `PIN_LOCKED` | Action `LoginWithPin`, `VerifyApproval` (`details.retry_after`) |
| 426 | `APP_UPDATE_REQUIRED` | Middleware `CheckAppVersion` |
| 429 | `TOO_MANY_REQUESTS` | Framework (`ThrottleRequestsException`) |
| 500 | `SERVER_ERROR` | Fallback semua exception tak terduga |
| 503 | `MAINTENANCE` | Framework (`php artisan down`) |

## 2. Aturan pemakaian

1. **Data tenant lain = `404`, bukan `403`.** Jangan bocorkan bahwa ID tersebut ada.
2. **Permission vs approval:** user tanpa permission dan aksi itu tidak punya jalur PIN → `FORBIDDEN`.
   Aksi punya jalur PIN (🔑) tetapi PIN tidak dikirim → `APPROVAL_REQUIRED`.
3. **Validasi bentuk vs aturan bisnis:** field salah/kurang → `VALIDATION_ERROR` (FormRequest).
   Data valid tetapi melanggar aturan → kode bisnis spesifik (Action).
4. **`SERVER_ERROR` tidak pernah dilempar manual.** Detail hanya di log/Sentry, `details: null`.
5. **`message` boleh spesifik**, tetapi app tidak boleh bercabang berdasarkan teks.

## 3. Melempar error di Action

```php
// Bentuk dasar sesuai SPEC
throw new BusinessException('SHIFT_NOT_OPEN', 'Shift belum dibuka');

// Bentuk yang disarankan: memakai enum agar status HTTP konsisten dan typo tertangkap Larastan
throw BusinessException::of(ErrorCode::PaymentExceedsBalance, 'Pembayaran melebihi sisa tagihan', [
    'remaining' => $remaining->toString(),
]);
```

`BusinessException::of()` mengambil status HTTP dari `ErrorCode::status()`, sehingga status tidak
ditulis ulang di setiap pemanggilan.

## 4. Menambah kode baru

Kode baru **tidak boleh** dibuat langsung di kode. Prosedur:

1. Tambahkan baris di tabel `docs/SPEC.md` (HTTP, kode, kapan) — dalam PR berlabel `spec`.
2. Setelah disetujui, tambahkan case di `App\Enums\ErrorCode`.
3. Perbarui tabel di file ini dan beri tahu tim Flutter (app harus menangani kode tersebut).

Format kode: `UPPER_SNAKE_CASE`, menjelaskan **kondisi** (`SHIFT_NOT_OPEN`), bukan aksi (`CANNOT_CHECKOUT`).

Usulan kode yang masih menunggu keputusan dicatat di `docs/SPEC.md` → *Keputusan & pertanyaan terbuka*.
