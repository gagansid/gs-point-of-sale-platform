# API — Auth & sistem

Standar: [`docs/standards/api/`](../standards/api/README.md). Kontrak teknis lengkap (skema field):
OpenAPI di `/docs/api` (local & staging).

Tiga jenis akses:

| Akses | Token | Didapat dari |
|---|---|---|
| Publik | — | — |
| Device | Device token (ability `device`, tanpa kedaluwarsa sampai dicabut) | `POST /devices` |
| User | Token user (owner 30 hari, kasir maks. 16 jam) | `POST /auth/login`, `POST /auth/pin-login` |

Token user tidak bisa memanggil endpoint device dan sebaliknya. Semua endpoint (kecuali
`/system/status`) wajib header `X-App-Version` (semver) dan opsional `X-App-Platform` (`android`/`ios`).

Alur aplikasi kasir:

```
Owner login email ─▶ POST /devices (simpan device_token) ─▶ layar PIN:
GET /devices/{uid}/cashiers (device token) ─▶ POST /auth/pin-login (device token) ─▶ token kasir
```

---

## `GET /api/v1/system/status`

Status sistem, versi minimal app, dan pengumuman aktif. Dipanggil saat app dibuka.

| | |
|---|---|
| Akses | Publik (tanpa `X-App-Version`) |
| Idempotent | Ya (baca) |
| Controller | `SystemController@status` |

### Respons sukses — `200`

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "maintenance": false,
    "server_time": "2026-10-08T07:02:11Z",
    "app_versions": [{ "platform": "android", "min_version": "1.0.0", "latest_version": "1.2.0", "force_update": false }],
    "announcements": [{ "id": "…", "title": "Maintenance", "body": "…", "starts_at": "…Z", "ends_at": "…Z" }]
  },
  "meta": { "request_id": "…" }
}
```

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 503 | `MAINTENANCE` | Sistem sedang maintenance |

---

## `POST /api/v1/auth/login`

Login owner/manager dengan email + kata sandi.

| | |
|---|---|
| Akses | Publik, rate limit `auth` (5/menit per IP + device, 20/menit per IP) |
| Idempotent | Satu token per `device_uid`: login ulang mencabut token lama di device itu |
| Action | `App\Actions\Auth\LoginWithPassword` |

### Request

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `email` | string | Ya | email, maks. 255, tidak peka huruf besar |
| `password` | string | Ya | maks. 255 |
| `device_uid` | string | Ya | `[A-Za-z0-9._:-]`, maks. 100 |

### Respons sukses — `200`

```json
{
  "success": true,
  "message": "Login berhasil",
  "data": {
    "token": "12|Xyz…",
    "token_type": "Bearer",
    "expires_at": "2026-11-07T07:02:11Z",
    "user": { "id": "…", "name": "Rina", "email": "rina@kopi.test", "role": "owner", "role_label": "Owner",
              "permissions": ["order.create", "…"], "outlet_id": null, "last_login_at": "…Z" },
    "tenant": { "id": "…", "name": "Kopi Senja", "business_type": "cafe", "status": "active", "subscription_ends_at": "…Z" },
    "outlet": { "id": "…", "code": "JKT01", "name": "…", "timezone": "Asia/Jakarta", "tax_rate": "11.00",
                "service_charge_rate": "5.00", "tax_inclusive": false, "rounding": 100,
                "receipt_header": null, "receipt_footer": "Terima kasih", "discount_limits": { "cashier": 10, "supervisor": 25 } }
  },
  "meta": { "request_id": "…" }
}
```

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Email/kata sandi salah atau akun nonaktif (pesan sama untuk semua kasus) |
| 403 | `FORBIDDEN` | Role kasir/supervisor ("Gunakan login PIN di perangkat kasir") |
| 403 | `TENANT_SUSPENDED` | Tenant ditangguhkan / langganan habis |
| 422 | `VALIDATION_ERROR` | Field tidak valid |
| 426 | `APP_UPDATE_REQUIRED` | Versi app usang (`error.details.min_version`, `latest_version`) |
| 429 | `TOO_MANY_REQUESTS` | Melewati rate limit (header `Retry-After`) |

### Test

`tests/Feature/Api/V1/Auth/LoginTest.php`

---

## `POST /api/v1/devices`

Mendaftarkan device kasir ke outlet dan menerbitkan **device token** (ADR 0002).

| | |
|---|---|
| Akses | Token user, permission `device.manage` (owner) |
| Idempotent | Per `device_uid`: daftar ulang = device yang sama, device token lama dicabut, status dicabut dibatalkan |
| Action | `App\Actions\Auth\RegisterDevice` |

### Request

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `name` | string | Ya | maks. 100 |
| `device_uid` | string | Ya | `[A-Za-z0-9._:-]`, maks. 100, unik per tenant |
| `platform` | enum | Tidak | `android`, `ios` |
| `app_version` | string | Tidak | semver `1.2.3` |
| `outlet_id` | uuid | Tidak | outlet milik tenant sendiri; kosong = outlet pertama |

### Respons sukses — `201`

```json
{
  "success": true,
  "message": "Perangkat berhasil didaftarkan",
  "data": {
    "device": { "id": "…", "outlet_id": "…", "name": "Kasir Depan", "device_uid": "sunmi-v2-001",
                "platform": "android", "app_version": "1.0.0", "last_seen_at": "…Z" },
    "device_token": "15|Abc…"
  },
  "meta": { "request_id": "…" }
}
```

`device_token` hanya ditampilkan **sekali** — simpan di secure storage.

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Tanpa token / memakai device token |
| 403 | `FORBIDDEN` | Tanpa permission `device.manage` |
| 403 | `TENANT_SUSPENDED` | Tenant ditangguhkan |
| 422 | `VALIDATION_ERROR` | Field tidak valid, termasuk `outlet_id` milik tenant lain |

### Test

`tests/Feature/Api/V1/Auth/RegisterDeviceTest.php`

---

## `GET /api/v1/devices/{uid}/cashiers`

Daftar karyawan untuk layar pilih nama sebelum login PIN.

| | |
|---|---|
| Akses | Device token milik `{uid}` |
| Isi | Karyawan aktif, punya PIN, di outlet device atau berlaku semua outlet (owner); urut nama |
| Action | `App\Actions\Auth\PinUsers` |

### Respons sukses — `200`

```json
{
  "success": true,
  "message": "OK",
  "data": [{ "id": "…", "name": "Budi", "role": "cashier", "role_label": "Kasir" }],
  "meta": { "request_id": "…" }
}
```

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Token tidak valid |
| 403 | `DEVICE_NOT_REGISTERED` | Memakai token user, `{uid}` bukan device pemilik token, atau device dicabut |
| 403 | `TENANT_SUSPENDED` | Tenant ditangguhkan |

### Test

`tests/Feature/Api/V1/Auth/CashiersTest.php`

---

## `POST /api/v1/auth/pin-login`

Login kasir/supervisor dengan PIN 6 digit di device terdaftar.

| | |
|---|---|
| Akses | Device token, rate limit `auth` |
| Idempotent | Satu token per user per device |
| Action | `App\Actions\Auth\LoginWithPin` → `VerifyPin` |

### Request

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `user_id` | uuid | Ya | dari `GET /devices/{uid}/cashiers` |
| `pin` | string | Ya | tepat 6 digit |

### Respons sukses — `200`

Sama dengan `POST /auth/login`; token berlaku maks. 16 jam dan terikat ke device
(mencabut device memutus token ini).

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `INVALID_PIN` | PIN salah, atau user tidak boleh login di device ini (pesan sama) |
| 403 | `DEVICE_NOT_REGISTERED` | Memakai token user / device dicabut |
| 403 | `TENANT_SUSPENDED` | Tenant ditangguhkan |
| 422 | `VALIDATION_ERROR` | `pin` bukan 6 digit, `user_id` bukan UUID |
| 423 | `PIN_LOCKED` | 5 kali salah → dikunci 15 menit; `error.details.retry_after` (detik) |
| 429 | `TOO_MANY_REQUESTS` | Rate limit |

### Catatan

- Hitungan percobaan disimpan dengan penguncian baris DB: percobaan paralel tetap terhitung.
- Saat terkunci, PIN yang benar pun ditolak sampai waktu kunci habis. Kunci yang sama berlaku
  untuk PIN approver (void, diskon di atas batas).

### Test

`tests/Feature/Api/V1/Auth/PinLoginTest.php`

---

## `GET /api/v1/auth/me`

User login beserta tenant, outlet, setelan outlet, dan daftar permission. Bentuk `data` sama
dengan respons login tanpa `token`, `token_type`, `expires_at`.

| HTTP | `error.code` | Kapan |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Tanpa token / device token / user dinonaktifkan |
| 403 | `DEVICE_NOT_REGISTERED` | Token kasir dari device yang sudah dicabut |
| 403 | `TENANT_SUSPENDED` | Tenant ditangguhkan / langganan habis |

## `POST /api/v1/auth/logout`

Menghapus token yang sedang dipakai saja (token di device lain tetap aktif). Respons `data: null`.

Test keduanya: `tests/Feature/Api/V1/Auth/MeLogoutTest.php`
