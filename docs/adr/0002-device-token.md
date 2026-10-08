# ADR 0002: Device kasir memiliki token sendiri

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q4, Q6

## Konteks

Layar PIN memanggil `GET /devices/{uid}/cashiers` dan `POST /auth/pin-login` **sebelum** ada user
yang login. Jika hanya memakai `device_uid`, siapa pun yang mengetahui UID bisa melihat daftar kasir
dan mencoba PIN.

## Pilihan

| Pilihan | Kelebihan | Kekurangan |
|---|---|---|
| A. `device_uid` saja | Sederhana | UID bukan rahasia; mudah ditebak/disalin |
| B. Device token Sanctum | Rahasia, bisa dicabut, tanpa package baru | Flutter menyimpan dua token |

## Keputusan

Kami memilih **B**:

- Model `Device` memakai `HasApiTokens`. `POST /devices` (owner) menerbitkan token dengan ability
  `device`, dikembalikan **sekali** di respons.
- Flutter menyimpan device token di secure storage dan mengirimnya sebagai `Authorization: Bearer`
  untuk `GET /devices/{uid}/cashiers` dan `POST /auth/pin-login`.
- Token kasir hasil PIN login terhubung ke device (`devices.id` disimpan di nama/metadata token).
- Middleware `EnsureDeviceRegistered` menolak device yang dicabut/beda tenant → `403 DEVICE_NOT_REGISTERED`.
- Mencabut device menghapus device token **dan** semua token user di device tersebut.

## Konsekuensi

- Route kasir pra-login memakai guard `auth:sanctum` + cek ability `device`.
- Rate limit PIN memakai kunci device + IP.
- Dokumentasi `docs/api/auth.md` menjelaskan kedua jenis token.
