# AGENTS.md — Panduan kerja untuk AI agent & developer

Dokumen ini adalah **titik masuk** bagi siapa pun (AI agent atau manusia) yang mengerjakan
`gs-point-of-sale-platform`. Baca dari atas ke bawah sebelum menulis kode.

> `CLAUDE.md` berisi aturan inti yang dimuat otomatis. `AGENTS.md` menjelaskan **cara bekerja**.
> Jika keduanya bertentangan, `docs/SPEC.md` menang, lalu `CLAUDE.md`, lalu dokumen standar.

---

## 1. Peta dokumen

| Pertanyaan | Buka |
|---|---|
| Apa yang dibangun & aturan bisnisnya? | [`docs/SPEC.md`](docs/SPEC.md) |
| Di mana file X harus diletakkan? | [`docs/standards/project-structure.md`](docs/standards/project-structure.md) |
| Bagaimana menulis kode PHP/Laravel? | [`docs/standards/coding.md`](docs/standards/coding.md) |
| Bagaimana membuat/mendokumentasikan endpoint? | [`docs/standards/api/`](docs/standards/api/README.md) |
| Bagaimana tampilan panel Filament? | [`docs/standards/ui/`](docs/standards/ui/README.md) |
| Aturan keamanan & checklist pentest? | [`docs/standards/security.md`](docs/standards/security.md) |
| Test apa yang wajib ada? | [`docs/standards/testing.md`](docs/standards/testing.md) |
| Alur issue → branch → PR → rilis? | [`docs/standards/workflow.md`](docs/standards/workflow.md) |
| Cara menulis dokumentasi & ADR? | [`docs/standards/documentation.md`](docs/standards/documentation.md) |
| Template (fitur, endpoint, ADR, PR) | [`docs/templates/`](docs/templates/) |
| Keputusan arsitektur yang sudah diambil | [`docs/adr/`](docs/adr/README.md) |

---

## 2. Aturan yang tidak boleh dilanggar

Ringkasan dari `CLAUDE.md` + `docs/SPEC.md`. Pelanggaran = PR ditolak.

1. **Logika bisnis hanya di `app/Actions`.** Controller & Filament hanya memanggil Action.
2. **Nominal order hanya dihitung `OrderCalculator`.** Nilai nominal dari client diabaikan.
3. **Setiap model bisnis memakai `BelongsToTenant`.** Query lintas tenant hanya di panel `/admin`.
4. **Cek permission, bukan role.** `$user->can('order.void')`; pemetaan hanya di `UserRole::permissions()`.
5. **ID `orders`/`payments` dari client = idempotency key.** Request ulang → data lama + `meta.idempotent_replay: true`.
6. **Semua respons API lewat `ApiResponse`**, error bisnis lewat `BusinessException`, kode error hanya dari daftar resmi.
7. **Uang `DECIMAL(15,2)` di DB, string `"62000.00"` di JSON.** Tidak pernah `float`.
8. **Aman dari input:** tanpa SQL mentah berisi input, tanpa `{!! !!}` untuk data user, semua input divalidasi dengan batas (`security.md` §2).
9. **Tanpa Redis, Docker, atau proses daemon.** Queue/cache/session memakai driver `database`.
10. **Tidak commit langsung ke `master`/`main`/`develop`.**
11. **Tidak menebak.** Jika spesifikasi tidak menjawab, catat di `docs/SPEC.md` → *Keputusan & pertanyaan terbuka* (status `Terbuka`) dan tanyakan.

---

## 3. Alur kerja per tugas

Setiap tugas (fitur, bug, refactor) mengikuti 6 langkah ini. Jangan melompati langkah.

```
1. PAHAMI → 2. RENCANA → 3. TEST → 4. IMPLEMENTASI → 5. VERIFIKASI → 6. DOKUMENTASI & PR
```

### 1. Pahami

- Baca bagian `docs/SPEC.md` yang relevan + standar terkait (lihat tabel §1).
- Identifikasi: Action apa, endpoint apa, permission apa, tabel apa, error code apa.
- Ada yang ambigu? **Berhenti dan tanyakan.** Jangan membuat error code, permission, atau kolom baru tanpa persetujuan.

### 2. Rencana

Untuk fitur > 1 file, tulis rencana singkat (di PR description atau `docs/features/{modul}.md`
memakai [`docs/templates/feature-spec.md`](docs/templates/feature-spec.md)):

- Daftar file yang dibuat/diubah (sesuai `project-structure.md`).
- Kontrak API (request, response, error) bila ada endpoint.
- Kasus test.

### 3. Test dulu

Tulis Pest test yang gagal sebelum implementasi. Minimal 5 kasus wajib untuk endpoint
(lihat `docs/standards/testing.md`): sukses, validasi gagal, permission ditolak, isolasi tenant,
request ganda.

### 4. Implementasi

Urutan yang disarankan (dari dalam ke luar):

```
Migration → Enum → Model (+BelongsToTenant) → Factory → Service → Action
→ FormRequest → API Resource → Controller → Route → Filament Resource/Page
```

### 5. Verifikasi

Semua harus hijau sebelum PR:

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
php artisan test
```

Untuk perubahan UI: buka panel di browser, cek light & dark mode, cek lebar mobile (375px).

### 6. Dokumentasi & PR

- Endpoint baru → dokumentasi di `docs/api/{modul}.md` (template: `docs/templates/endpoint.md`)
  + PHPDoc untuk Scramble.
- Keputusan arsitektur baru → ADR di `docs/adr/`.
- Isi checklist *Definition of Done* (§5) di deskripsi PR.

---

## 4. Pemetaan tugas → lokasi kode

| Saya ingin... | Buat/ubah |
|---|---|
| Menambah use case bisnis | `app/Actions/{Domain}/{VerbNoun}.php` |
| Menambah perhitungan bersama | `app/Services/{Name}.php` |
| Menambah endpoint | `routes/api.php` + `Http/Controllers/Api/V1/{Domain}Controller.php` + `Http/Requests/Api/V1/{Domain}/{Verb}{Noun}Request.php` + `Http/Resources/Api/V1/{Noun}Resource.php` |
| Menambah permission | `UserRole::permissions()` + tabel permission di `docs/SPEC.md` |
| Menambah error code | Tabel error code di `docs/SPEC.md` **dulu** (PR terpisah/disetujui), lalu `App\Enums\ErrorCode` |
| Menambah status/tipe | `app/Enums/{Name}.php` (dengan label & warna untuk UI) |
| Menambah menu dashboard owner | `app/Filament/Dashboard/Resources` atau `Pages` |
| Menambah menu super admin | `app/Filament/Admin/Resources` atau `Pages` |
| Mengubah tampilan panel | `resources/css/filament/{panel}/theme.css` — ikuti `docs/standards/ui/` |
| Menambah tugas terjadwal | `routes/console.php` |
| Menambah setelan POS | `config/pos.php` |

Detail lengkap: [`docs/standards/project-structure.md`](docs/standards/project-structure.md).

---

## 5. Definition of Done

Sebuah tugas dianggap selesai jika **semua** poin berikut terpenuhi:

- [ ] Sesuai `docs/SPEC.md`; pertanyaan terbuka yang relevan sudah dijawab.
- [ ] Logika bisnis di Action, dalam `DB::transaction`.
- [ ] Permission dicek lewat `can()`; tidak ada pengecekan role langsung.
- [ ] Model bisnis baru memakai `BelongsToTenant` + `HasUuids`.
- [ ] Respons API memakai envelope standar; error memakai kode resmi.
- [ ] Pest test: sukses, validasi, permission, isolasi tenant, request ganda (bila relevan).
- [ ] Aturan keamanan `docs/standards/security.md` §2 dipenuhi.
- [ ] `composer check` hijau (Pint, Larastan, Pest, audit dependency).
- [ ] Endpoint terdokumentasi (`docs/api/` + Scramble terbaca benar).
- [ ] UI mengikuti `docs/standards/ui/` (token, komponen, teks Bahasa Indonesia, dark mode).
- [ ] Commit Conventional Commits, branch sesuai konvensi, PR template terisi.

---

## 6. Larangan untuk AI agent

- Jangan menjalankan `migrate:fresh`, `db:wipe`, atau menghapus data tanpa izin eksplisit.
- Jangan mengubah `.env`, kredensial, atau file di `storage/` milik user.
- Jangan menambah package Composer/NPM tanpa menyebut alasan & persetujuan (lihat tabel package di SPEC).
- Jangan menonaktifkan test, menurunkan level Larastan, atau menambah `@phpstan-ignore` tanpa alasan tertulis.
- Jangan push, merge, atau membuat tag rilis kecuali diminta.
- Jangan menulis ulang file standar untuk "menyesuaikan" kode; ubah kode agar sesuai standar,
  atau usulkan perubahan standar secara terpisah.

---

## 7. Cara melapor hasil kerja

Akhiri setiap tugas dengan ringkasan singkat:

```
Selesai: <apa yang dikerjakan>
File: <daftar file utama>
Test: <perintah + hasil (lulus/gagal)>
Belum/terbuka: <hal yang ditunda atau perlu keputusan>
```
