<!--
Template dokumentasi endpoint. Salin blok di bawah ke docs/api/{domain}.md,
satu blok per endpoint, urut sesuai tabel API di docs/SPEC.md.
-->

## `{METHOD} /api/v1/{path}`

{Ringkasan satu kalimat.}

| | |
|---|---|
| Permission | `{permission}` (🔑 {permission} via PIN approver, jika ada) |
| Idempotent | Ya — kunci: `{field}` / Tidak |
| Action | `App\Actions\{Domain}\{Name}` |
| Rate limit | API umum / auth |

### Request

| Field | Tipe | Wajib | Aturan |
|---|---|---|---|
| `id` | uuid | Ya | UUID v7 dari client |
| `...` | | | |

```json
{
}
```

### Respons sukses — `{200|201}`

```json
{
  "success": true,
  "message": "...",
  "data": {},
  "meta": { "request_id": "...", "idempotent_replay": false }
}
```

### Error

| HTTP | `error.code` | Kapan |
|---|---|---|
| 422 | `VALIDATION_ERROR` | ... |
| 403 | `FORBIDDEN` | ... |
| 404 | `NOT_FOUND` | ID tidak ada / milik tenant lain |

### Catatan

- Aturan bisnis yang tidak terlihat dari skema (urutan perhitungan, lock, efek ke stok).

### Test

`tests/Feature/Api/V1/{Domain}/{Name}Test.php`
