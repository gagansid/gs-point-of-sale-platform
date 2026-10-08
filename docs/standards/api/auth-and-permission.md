# Autentikasi & Permission

## 1. Jenis token

| Pengguna | Endpoint | Token | Masa berlaku | Ability |
|---|---|---|---|---|
| Device | `POST /devices` (oleh owner) | Sanctum milik `Device`, ability `device` | Sampai dicabut | Hanya `GET /devices/{uid}/cashiers`, `POST /auth/pin-login` |
| Owner / manager | `POST /auth/login` (email + password) | Sanctum, nama = device_uid | Sesuai `config('pos.owner_token_ttl')` | `*` (dibatasi permission) |
| Kasir | `POST /auth/pin-login` (user_id + PIN, dengan device token) | Sanctum | Maks. 16 jam (`config('pos.cashier_token_ttl')`) | `*` (dibatasi permission) |

- Satu token per user per device. Login ulang di device yang sama mencabut token lama.
- Mencabut device (`device.manage`) menghapus semua token yang terkait device tersebut.
- Device token dikembalikan sekali saat pendaftaran dan disimpan Flutter di secure storage (ADR 0002).
- Token kedaluwarsa dibersihkan oleh `sanctum:prune-expired` (cron harian).

## 2. Urutan middleware

```php
Route::prefix('v1')->middleware(['api', AssignRequestId::class, CheckAppVersion::class])->group(function () {
    // Publik
    Route::get('system/status', ...);
    Route::middleware('throttle:auth')->group(fn () => /* login, pin-login */);

    // Terautentikasi
    Route::middleware([
        'auth:sanctum',
        'tenant',                      // SetTenantContext: sebelum route model binding (priority list)
        EnsureTenantActive::class,     // 403 TENANT_SUSPENDED
        EnsureDeviceRegistered::class, // device token masih sah
        'throttle:api',
    ])->group(base_path('routes/api/v1.php'));
});
```

`SetTenantContext` **wajib** berjalan sebelum query model apa pun; tanpa itu global scope tidak aktif.

## 3. Permission

- Satu-satunya pemetaan: `App\Enums\UserRole::permissions()`. `Gate::before` memanggil `allows()`
  **hanya** untuk ability di `UserRole::PERMISSIONS`; ability lain (`view`, `update`) diputuskan Policy (ADR 0005).
- Cek di tiga tempat, sesuai kebutuhan:

| Tempat | Untuk | Contoh |
|---|---|---|
| `FormRequest::authorize()` | Permission dasar endpoint | `return $this->user()->can('product.manage');` |
| Policy | Kepemilikan data (mis. `order.view_own` vs `order.view_all`) | `OrderPolicy::view()` |
| Action | Jalur PIN approval & aturan bisnis | `VerifyApproval` |

- Dilarang: `$user->role === UserRole::Owner`, `in_array($user->role, ...)`, `hasRole()`.
- Permission baru → tambahkan ke `UserRole` **dan** tabel permission di `docs/SPEC.md`.

## 4. PIN approval (🔑)

Untuk `order.void` dan `order.discount_over_limit` saat pelaku tidak punya permission.

Request membawa:

```json
{ "approver_user_id": "0192f3a1-...", "approver_pin": "123456" }
```

Alur `VerifyApproval::handle($actor, $permission, $approval)`:

```
pelaku punya permission?           ── ya ──▶ lanjut, approved_by = null
        │ tidak
approval dikirim?                  ── tidak ─▶ 403 APPROVAL_REQUIRED
        │ ya
approver = pelaku?                 ── ya ───▶ 403 SELF_APPROVAL_NOT_ALLOWED
        │ tidak
approver tenant sama, aktif, PIN cocok? ─ tidak ─▶ 401 INVALID_PIN  (hitung percobaan gagal)
        │ ya
approver punya permission?         ── tidak ─▶ 403 FORBIDDEN
        │ ya
lanjut, approved_by = approver_user_id
```

## 5. PIN & password

- Hash dengan `Hash::make()` (bcrypt/argon2). PIN tidak pernah dikembalikan di respons atau log.
- PIN: 6 digit. Terkunci 15 menit setelah 5 kali salah → `423 PIN_LOCKED` (berlaku juga untuk PIN approver).
- Password minimal 8 karakter.

## 6. Panel web

| Panel | Guard | Tabel | Catatan |
|---|---|---|---|
| `/dashboard` | `web` | `users` | Filament tenancy; hanya role dengan permission dashboard |
| `/admin` | `admin` | `admins` | Terpisah total dari tenant; disarankan 2FA |
