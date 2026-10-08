# Standar Alur Pengerjaan

## 1. Siklus pekerjaan

```
Issue ─▶ Branch ─▶ Rencana ─▶ Test ─▶ Kode ─▶ Verifikasi lokal ─▶ PR ─▶ Review + CI ─▶ Merge ─▶ Rilis
```

| Tahap | Keluaran | Penanggung jawab |
|---|---|---|
| Issue | Judul, konteks, acceptance criteria, label domain | Pembuat tugas |
| Branch | `feature/{issue}-{deskripsi}` dari `develop` | Developer / agent |
| Rencana | Daftar file + kontrak API + kasus test (di issue/PR) | Developer / agent |
| Test → Kode | Test gagal dulu, lalu implementasi | Developer / agent |
| Verifikasi | Pint, Larastan, Pest hijau; cek UI | Developer / agent |
| PR | Template terisi, DoD tercentang | Developer / agent |
| Review | ≥ 1 approval, CI hijau | Reviewer |
| Merge | Squash merge ke `develop` | Reviewer |
| Rilis | `develop` → `main` + tag | Lead |

## 2. Issue

Setiap pekerjaan dimulai dari issue (GitHub Issues) dengan format:

```markdown
## Konteks
Kenapa ini dibutuhkan. Rujuk bagian SPEC: docs/SPEC.md#shift--void

## Acceptance criteria
- [ ] Kasir bisa void dengan PIN supervisor
- [ ] Approver = kasir → SELF_APPROVAL_NOT_ALLOWED

## Di luar cakupan
- Audit log (fase 2)
```

Label: domain (`order`, `shift`, ...), jenis (`feature`, `bug`, `chore`, `docs`, `spec`), dan
prioritas (`p1`–`p3`).

## 3. Branch

| Branch | Dari | Merge ke | Contoh |
|---|---|---|---|
| `main` | — | — | Production, selalu siap rilis, diproteksi |
| `develop` | `main` | `main` saat rilis | Staging, diproteksi |
| `feature/{issue}-{deskripsi}` | `develop` | `develop` | `feature/12-checkout-api` |
| `fix/{issue}-{deskripsi}` | `develop` | `develop` | `fix/25-rounding-tax-inclusive` |
| `hotfix/{deskripsi}` | `main` | `main` **dan** `develop` | `hotfix/void-stock-not-restored` |
| `chore/{deskripsi}` | `develop` | `develop` | `chore/upgrade-filament` |
| `docs/{deskripsi}` | `develop` | `develop` | `docs/api-standards` |

Aturan nama: huruf kecil, kebab-case, maks. ±50 karakter, diawali nomor issue jika ada.

> **Berlaku saat ini (ADR 0003):** selama fase MVP semua pekerjaan di-commit ke `feature/pos`,
> lalu digabung ke `master` lewat PR saat siap rilis. Tabel di atas adalah target setelah MVP.
> Tanpa PR per fitur, checklist reviewer (§5) dipakai sebagai self-review sebelum setiap commit.

## 4. Commit

Format Conventional Commits: `{tipe}({scope}): {deskripsi}` — deskripsi bahasa Inggris, huruf kecil,
kalimat perintah, tanpa titik, maks. 72 karakter.

| Tipe | Kapan |
|---|---|
| `feat` | Fitur baru |
| `fix` | Perbaikan bug |
| `refactor` | Perubahan struktur tanpa mengubah perilaku |
| `test` | Menambah/memperbaiki test |
| `docs` | Dokumentasi |
| `chore` | Dependency, konfigurasi |
| `ci` | Pipeline GitHub Actions |

Scope: `auth`, `catalog`, `product`, `shift`, `order`, `payment`, `report`, `dashboard`, `admin`, `db`.
Perubahan dokumen standar tanpa modul: `docs(standards): ...`.

```
feat(order): add checkout endpoint
fix(payment): reject non-cash overpayment
test(order): cover idempotent checkout
docs(standards): add ui component guidelines
```

Commit kecil dan bermakna; satu commit tidak mencampur refactor dengan fitur.

## 5. Pull Request

- Judul = format commit (dipakai saat squash merge).
- Deskripsi memakai `.github/pull_request_template.md`.
- Ukuran ideal < 400 baris perubahan (di luar test & migration). Fitur besar dipecah:
  migration+model → Action+test → endpoint → Filament.
- PR mengubah SPEC/standar diberi label `spec` atau `docs` dan dipisah dari PR kode.

### Checklist reviewer

- [ ] Sesuai SPEC & acceptance criteria.
- [ ] Aturan inti `AGENTS.md` §2 tidak dilanggar.
- [ ] Test mencakup 5 kasus wajib; nama test menjelaskan perilaku.
- [ ] Tidak ada N+1, query lintas tenant, atau `float` untuk uang.
- [ ] Migration bisa di-rollback; tidak mengubah migration yang sudah dirilis.
- [ ] UI sesuai standar komponen; teks Bahasa Indonesia.

## 6. CI (GitHub Actions)

Berjalan di setiap push & PR ke `develop`/`main`:

```
composer install → pint --test → phpstan analyse → php artisan test (SQLite)
                                                 → php artisan test --group=mysql (MySQL 8)
```

`main` dan `develop` hanya bisa di-merge jika CI hijau.

## 7. Rilis

1. Semantic Versioning `MAJOR.MINOR.PATCH`, tag `v1.0.0` di `main`.
2. PR `develop` → `main`, isi changelog di deskripsi PR.
3. Tag, lalu deploy sesuai SPEC → *Deploy ke cPanel*.
4. Perubahan API yang merusak app lama → naikkan `min_version` di `/admin` atau buat `/api/v2`
   (lihat `docs/standards/api/conventions.md` §7).

## 8. Migration

- Satu migration per perubahan; nama deskriptif (`create_orders_table`, `add_timezone_to_outlets_table`).
- Migration yang sudah sampai `develop` **tidak boleh diubah** — buat migration baru.
- Kolom baru pada tabel besar (`orders`, `order_items`) harus nullable atau punya default.
