# API — Shift

Semua endpoint memakai **token user yang terikat device kasir** (login PIN, atau login email dengan
`device_uid` device terdaftar). Token tanpa device → `403 DEVICE_NOT_REGISTERED`.

Shift milik **perangkat** dan dipakai bergantian (ADR 0017, SPEC Q87–Q89): ganti kasir = kunci layar → login PIN
karyawan lain di device yang sama, **tanpa hitung uang**. Uang dihitung sekali saat tutup shift.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/shifts/current` | semua | baca | — |
| POST | `/shifts` | `shift.operate` (+ 🔑 `shift.edit_opening_cash` bila kas awal ≠ carry-over) | ya: satu shift terbuka per device | `OpenShift` |
| POST | `/shifts/{id}/close` | `shift.operate`, dari device shift itu | ya: tutup ulang = hasil sama | `CloseShift` |
| GET | `/shifts/{id}/summary` | pembuka/operator shift atau `shift.view_all` | baca | `ShiftSummary` |
| POST | `/shifts/{id}/force-close` | `shift.force_close` | ya | `CloseShift(force)` |
| POST | `/shifts/{id}/opening-cash` | `shift.operate` + 🔑 `shift.edit_opening_cash`, dari device shift itu | ya: nilai sama = tanpa perubahan | `CorrectOpeningCash` |
| GET | `/shifts/{id}/cash-movements` | sama dengan `summary` | baca | — |
| POST | `/cash-movements` | `shift.operate` (+ 🔑 `shift.cash_out_over_limit` di atas batas) | ya: `id` dari aplikasi | `RecordCashMovement` |

🔑 = kasir tanpa izin mengirim `approver_user_id` + `approver_pin` (6 digit) atasan yang punya izin & ditugaskan di
outlet itu. Approver = pelaku → `403 SELF_APPROVAL_NOT_ALLOWED`; tanpa PIN → `403 APPROVAL_REQUIRED`.

## Alur ganti kasir (aplikasi)

1. Kasir menekan **Kunci** (atau kunci otomatis setelah `outlet.auto_lock_minutes` menit tanpa sentuhan; dari
   `GET /auth/me` → `data.outlet`, `null` = mati). Aplikasi menghapus token kasir dari layar.
2. Karyawan berikutnya memilih nama + PIN → `POST /auth/pin-login`. Server mencatat dia sebagai **operator** shift
   yang sedang terbuka di device (log `operators`), shift & laci tetap.
3. Transaksi berikutnya tercatat atas nama operator baru (`orders.user_id`). Riwayat order shift berjalan di device
   ini terlihat oleh siapa pun yang bertugas.

## `GET /v1/shifts/current`

`data` = shift terbuka (dengan `operators`) atau `null`. Bila `null`, `meta.carry_over_cash` berisi kas awal yang
akan dipakai bila shift dibuka sekarang (sisa laci shift terakhir di device ini), atau `null` = kas awal wajib diketik.

## `POST /v1/shifts`

| Field | Wajib | Aturan |
|---|---|---|
| `opening_cash` | tidak | ≥ 0. Kosong = carry-over; wajib bila device belum punya shift sebelumnya yang mencatat sisa laci (`422`) |
| `opening_cash_note` | bila `opening_cash` ≠ carry-over | maks. 255 (alasan koreksi) |
| `approver_user_id`, `approver_pin` | 🔑 | lihat di atas |

`data.opening_cash_source`: `manual` (diketik), `carry_over` (sisa laci), `adjusted` (dikoreksi; `carried_cash` =
nilai semula). Device sudah punya shift terbuka → `200` + shift itu (`meta.idempotent_replay: true`), pengirim
dicatat sebagai operator. Dijamin oleh index unik `shifts.open_device_key` (aman dari request bersamaan).

## `POST /v1/shifts/{id}/close` · `/force-close`

| Field | Wajib | Aturan |
|---|---|---|
| `actual_cash` | Ya | uang di laci (kas dihitung), ≥ 0 |
| `cash_deposited` | tidak | 0 ≤ disetor ≤ `actual_cash`. Kosong = `actual_cash` − `cash_float` outlet (minimal 0) bila modal laci tetap diatur, selain itu 0 |
| `note` | close: tidak · force-close: **ya** | maks. 255 |

```json
{
  "success": true,
  "message": "Shift berhasil ditutup",
  "data": {
    "shift": { "id": "…", "status": "closed", "opening_cash": "200000.00", "opening_cash_source": "carry_over",
               "carried_cash": "200000.00", "expected_cash": "270900.00", "actual_cash": "270000.00",
               "difference": "-900.00", "cash_deposited": "70000.00", "closing_float": "200000.00", "closed_at": "…Z",
               "operators": [{ "user_id": "…", "name": "Budi", "started_at": "…Z", "ended_at": "…Z" }] },
    "summary": {
      "order_count": 2, "sales_total": "181800.00",
      "payment_methods": [{ "payment_method_id": "…", "name": "Tunai", "category": "cash", "count": 1, "amount": "90900.00" }],
      "opening_cash": "200000.00", "cash_sales": "90900.00", "cash_in": "0.00", "cash_out": "20000.00", "expected_cash": "270900.00"
    }
  },
  "meta": { "request_id": "…", "idempotent_replay": false }
}
```

- `expected_cash` = kas awal + Σ pembayaran tunai (bersih dari kembalian, hanya order selesai) + kas masuk − kas keluar.
- `difference` = aktual − seharusnya (minus = kurang); setoran tidak memengaruhi selisih.
- `closing_float` = aktual − disetor = kas awal shift berikutnya di **device yang sama**.
- Tutup biasa dari device lain → `403 FORBIDDEN` ("Gunakan tutup paksa"). Disetor > aktual → `422 VALIDATION_ERROR`.

## `POST /v1/shifts/{id}/opening-cash`

Koreksi kas awal shift berjalan. Body: `opening_cash` (wajib, ≥ 0), `note` (wajib, alasan), approver 🔑. Shift sudah
ditutup → `409 SHIFT_NOT_OPEN`; dari device lain → `403 FORBIDDEN`. Respons = shift (`opening_cash_source: adjusted`).

## `POST /v1/cash-movements`

| Field | Wajib | Aturan |
|---|---|---|
| `id` | Ya | UUID dari aplikasi (idempotency key) |
| `type` | Ya | `in` / `out` |
| `reason` | Ya | `operational` (Belanja operasional, hanya `out`), `deposit` (Setor ke owner, hanya `out`), `change` (Tukar/tambah kembalian), `other` (Lainnya) |
| `amount` | Ya | > 0, maks. 2 desimal |
| `note` | bila `reason` = `other` | maks. 255 |
| `approver_user_id`, `approver_pin` | 🔑 kas keluar > `cash_out_approval_limit` outlet | `APPROVAL_REQUIRED` membawa `error.details.limit` |

- Masuk ke shift yang terbuka di device ini; belum ada → `409 SHIFT_NOT_OPEN`.
- Kas keluar melebihi kas seharusnya di laci → `422 VALIDATION_ERROR` (`error.details.amount`).
- `id` sudah dipakai di outlet ini → `200` + data lama (`meta.idempotent_replay: true`); milik tenant/outlet lain → `404`.
- Tidak ada ubah/hapus: koreksi dengan catatan lawan (mis. kas masuk untuk membatalkan kas keluar yang salah).

Respons `201`: `{ id, shift_id, type, reason, reason_label, amount, note, user_id, user_name, approved_by, approved_by_name, created_at }`.

Test: `tests/Feature/Api/V1/Shift/ShiftTest.php`, `SharedShiftTest.php`, `CashMovementTest.php`
