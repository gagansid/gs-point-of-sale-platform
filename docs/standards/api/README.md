# Standar API

Acuan untuk semua endpoint di `/api/v1`. Konsumen utama: aplikasi Flutter `gs-point-of-sale-app`.

## Prinsip

1. **Satu bentuk respons.** Flutter hanya punya satu parser, bercabang pada `error.code`.
2. **Server sumber kebenaran.** Nominal dihitung ulang; nilai dari client hanya pratinjau.
3. **Aman diulang.** Request yang mengubah transaksi memakai ID dari client sebagai idempotency key.
4. **Tidak merusak app lama.** Perubahan yang merusak → naikkan `min_version` atau buat `/api/v2`.
5. **Didokumentasikan dari kode.** Scramble membaca FormRequest + Resource; dokumen Markdown
   menjelaskan alur & aturan bisnis yang tidak terlihat dari kode.

## Daftar standar

| File | Isi |
|---|---|
| [conventions.md](conventions.md) | URL, method, header, penamaan field, tipe data, pagination, filter, versi |
| [response-envelope.md](response-envelope.md) | Bentuk respons sukses, daftar, dan gagal |
| [error-codes.md](error-codes.md) | Kode error resmi, kapan dipakai, cara menambah |
| [auth-and-permission.md](auth-and-permission.md) | Token, device, PIN, permission, PIN approval |
| [idempotency.md](idempotency.md) | ID dari client, replay, penguncian |
| [implementation.md](implementation.md) | Pola kode: Route → Request → Controller → Action → Resource |

## Dokumentasi endpoint

| Sumber | Untuk | Lokasi |
|---|---|---|
| OpenAPI (Scramble) | Kontrak teknis otomatis | `/docs/api` (lokal & staging saja) |
| Markdown per domain | Alur, aturan bisnis, contoh, error per endpoint | `docs/api/{domain}.md` memakai [`docs/templates/endpoint.md`](../../templates/endpoint.md) |

Setiap endpoint baru wajib tercatat di keduanya. Jika keduanya berbeda, **kode + Scramble** yang
benar, dan Markdown harus diperbaiki di PR yang sama.

## Checklist endpoint baru

- [ ] Terdaftar di tabel API `docs/SPEC.md` (method, URL, role).
- [ ] Route bernama `api.v1.{resource}.{action}` di grup middleware yang benar.
- [ ] FormRequest: aturan validasi + `authorize()` dengan permission.
- [ ] Controller memanggil tepat satu Action (untuk endpoint tulis).
- [ ] Respons via `ApiResponse` + API Resource; uang string, tanggal ISO UTC, field kosong `null`.
- [ ] Error memakai kode dari [error-codes.md](error-codes.md).
- [ ] Idempotent bila membuat order/payment.
- [ ] Pest feature test 5 kasus wajib (lihat `docs/standards/testing.md`).
- [ ] `docs/api/{domain}.md` diperbarui.
