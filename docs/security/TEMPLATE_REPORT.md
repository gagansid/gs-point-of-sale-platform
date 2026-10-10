# Laporan Audit Keamanan gs.POS — YYYY-MM-DD

- **Auditor:** <nama/agent>
- **Commit:** `<hash>` (branch `feature/pos`)
- **Lingkungan:** lokal (`<host>`)
- **Prompt:** [`docs/security/AUDIT_PROMPT.md`](../AUDIT_PROMPT.md)

## 1. Ringkasan Eksekutif

| Severity | Jumlah | Fixed | Open |
|---|---|---|---|
| Critical | 0 | 0 | 0 |
| High | 0 | 0 | 0 |
| Medium | 0 | 0 | 0 |
| Low | 0 | 0 | 0 |
| Info | 0 | 0 | 0 |

<2–4 kalimat: kondisi umum keamanan, risiko terbesar.>

## 2. Attack Surface

| Komponen | Entry point | Auth | Catatan |
|---|---|---|---|
| API Flutter | `/api/v1/*` | Sanctum token | |
| Dashboard | `/dashboard` | session (web) | |
| Admin | `/admin` | session (guard admin) | |
| Landing | `/` | publik | |

## 3. Daftar Temuan

| ID | Judul | Severity | Kategori | Status | Fix |
|---|---|---|---|---|---|
| GSPOS-SEC-001 | | | | CONFIRMED | Open |

## 4. Detail Temuan

### GSPOS-SEC-001 — <judul>

| Field | Isi |
|---|---|
| Severity | |
| Kategori | |
| Status verifikasi | CONFIRMED / PLAUSIBLE |
| Lokasi | `app/...php:line` |

**Deskripsi & root cause**

**Dampak**

**Reproduksi / PoC**

```php
// tests/Feature/Security/GspoSec001Test.php
```

**Rekomendasi**

**Test regresi**

---

## 5. Hasil Tooling

| Tool | Hasil |
|---|---|
| `php artisan test` | |
| `./vendor/bin/phpstan analyse` | |
| `./vendor/bin/pint --test` | |
| `composer audit` | |

## 6. Security Baseline Checklist

- [ ] Setiap model bisnis memakai `BelongsToTenant`; tidak ada `withoutGlobalScopes` di luar `/admin`
- [ ] Validasi `exists:`/`unique:` difilter `tenant_id`
- [ ] Setiap endpoint & Filament Resource dilindungi permission (`can()`) + test permission ditolak
- [ ] Test isolasi tenant untuk setiap endpoint baru
- [ ] Nominal dihitung ulang server (`OrderCalculator`); input harga client diabaikan
- [ ] Idempotency order/payment diuji (replay & payload berbeda)
- [ ] Aksi PIN menyimpan `approved_by` dan menolak self-approval
- [ ] Rate limit pada login, PIN, reset password, checkout
- [ ] Tidak ada `{!! !!}`/`HtmlString` dengan input user
- [ ] Upload: whitelist MIME/ekstensi, tanpa SVG, disimpan di luar web root yang executable
- [ ] `APP_DEBUG=false`, file sensitif tidak terekspos, security headers aktif
- [ ] Log bebas password/PIN/token
- [ ] `composer audit` bersih

## 7. Prioritas Perbaikan

| Prioritas | ID | Alasan |
|---|---|---|
| Quick win | | |
| Struktural | | |

## 8. Di Luar Scope / Belum Diuji

-
