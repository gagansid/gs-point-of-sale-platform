# API — Shift

Semua endpoint memakai **token user yang terikat device kasir** (login PIN, atau login email dengan
`device_uid` device terdaftar). Token tanpa device → `403 DEVICE_NOT_REGISTERED`.

| Method | Endpoint | Permission | Idempotent | Action |
|---|---|---|---|---|
| GET | `/shifts/current` | semua | baca | — |
| POST | `/shifts` | `shift.operate` | ya: satu shift terbuka per device | `OpenShift` |
| POST | `/shifts/{id}/close` | `shift.operate` + pembuka shift | ya: tutup ulang = hasil sama | `CloseShift` |
| GET | `/shifts/{id}/summary` | pembuka shift atau `shift.view_all` | baca | `ShiftSummary` |
| POST | `/shifts/{id}/force-close` | `shift.force_close` | ya | `CloseShift(force)` |

## `POST /v1/shifts`

Body `{ "opening_cash": 200000 }`. Device sudah punya shift terbuka → `200` + shift itu
(`meta.idempotent_replay: true`, pesan "Shift sudah terbuka"), siapa pun yang membukanya.
Dijamin oleh index unik `shifts.open_device_key` (aman dari request bersamaan).

## `POST /v1/shifts/{id}/close` · `/force-close`

| Field | Wajib | Aturan |
|---|---|---|
| `actual_cash` | Ya | uang di laci, ≥ 0 |
| `note` | close: tidak · force-close: **ya** | maks. 255 |

```json
{
  "success": true,
  "message": "Shift berhasil ditutup",
  "data": {
    "shift": { "id": "…", "status": "closed", "opening_cash": "200000.00", "expected_cash": "290900.00",
               "actual_cash": "290000.00", "difference": "-900.00", "closed_at": "…Z" },
    "summary": {
      "order_count": 2, "sales_total": "181800.00",
      "payment_methods": [{ "payment_method_id": "…", "name": "Tunai", "category": "cash", "count": 1, "amount": "90900.00" }],
      "opening_cash": "200000.00", "cash_sales": "90900.00", "expected_cash": "290900.00"
    }
  },
  "meta": { "request_id": "…", "idempotent_replay": false }
}
```

- `expected_cash` = kas awal + Σ pembayaran tunai (sudah bersih dari kembalian), hanya order selesai.
- `difference` = aktual − seharusnya (minus = kurang).
- Tutup biasa oleh selain pembuka shift → `403 FORBIDDEN` ("Gunakan tutup paksa").

Test: `tests/Feature/Api/V1/Shift/ShiftTest.php`
