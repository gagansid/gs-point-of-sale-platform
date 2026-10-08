# Standar Dokumentasi

## 1. Jenis dokumen & tempatnya

| Dokumen | Lokasi | Kapan diperbarui | Pemilik |
|---|---|---|---|
| Spesifikasi produk | `docs/SPEC.md` | Saat aturan bisnis/API/role berubah (PR berlabel `spec`) | Product owner / lead |
| Aturan inti agent | `CLAUDE.md` | Jarang; hanya aturan yang wajib dimuat di setiap sesi | Lead |
| Cara kerja | `AGENTS.md` | Saat alur kerja berubah | Lead |
| Standar | `docs/standards/**` | Saat konvensi berubah (PR berlabel `docs`) | Lead |
| Dokumentasi endpoint | `docs/api/{domain}.md` | Setiap endpoint baru/berubah, di PR yang sama dengan kode | Developer |
| Spesifikasi fitur | `docs/features/{fitur}.md` | Sebelum fitur kompleks dikerjakan (opsional) | Developer |
| Keputusan arsitektur | `docs/adr/NNNN-{judul}.md` | Saat memilih di antara alternatif yang berdampak jangka panjang | Developer / lead |
| Setup lokal | `README.md` | Saat langkah setup berubah | Developer |
| OpenAPI | Otomatis (Scramble) | Otomatis dari kode | — |
| Komentar kode | Di kode | Bersama kode | Developer |

**Aturan utama:** dokumen yang berubah karena kode **masuk ke PR yang sama** dengan kodenya.

## 2. Hierarki kebenaran

Jika dua sumber bertentangan:

```
docs/SPEC.md  >  CLAUDE.md  >  docs/standards/**  >  docs/api/**, docs/features/**  >  komentar kode
```

Pertentangan yang ditemukan harus diperbaiki (PR), bukan dibiarkan.

## 3. Gaya penulisan

- Bahasa Indonesia baku dan ringkas. Nama kode, perintah, dan istilah teknis tetap asli (`Action`, `lockForUpdate`).
- Kalimat pendek. Satu paragraf satu gagasan. Utamakan **tabel** dan **daftar** daripada paragraf panjang.
- Gunakan bentuk perintah untuk aturan: "Pakai `ApiResponse`", bukan "Sebaiknya kita memakai...".
- Kata wajib/larangan ditulis jelas: **wajib**, **dilarang**, **boleh**, **disarankan**.
- Contoh kode harus bisa dijalankan (atau ditandai `// potongan`) dan mengikuti `coding.md`.
- Tidak menyalin isi dokumen lain; tautkan saja (`lihat [error-codes.md](api/error-codes.md)`).

## 4. Format Markdown

| Elemen | Aturan |
|---|---|
| Judul | Satu `#` per file; bagian bernomor `## 1. ...` untuk dokumen standar |
| Code block | Selalu dengan bahasa: ` ```php `, ` ```json `, ` ```bash ` |
| Tabel | Untuk perbandingan, pemetaan, daftar aturan |
| Tautan | Relatif antar file (`../api/README.md`) |
| Diagram | ASCII di code block (tanpa tool eksternal) |
| Nama file | kebab-case, huruf kecil: `response-envelope.md` |
| Panjang baris | Bebas; jangan memotong kalimat di tengah tabel |

## 5. Komentar kode

| Wajib | Tidak perlu |
|---|---|
| PHPDoc method publik Controller (dibaca Scramble) | Komentar yang mengulang nama method |
| Alasan di balik rumus, lock, idempotency, workaround | Komentar "// simpan data" sebelum `->save()` |
| Tipe generik array/collection | Kode yang dikomentari (hapus saja) |
| `@throws BusinessException` di Action | — |

Format TODO: `// TODO(#issue): penjelasan` — TODO tanpa nomor issue tidak boleh di-merge.

## 6. ADR (Architecture Decision Record)

Tulis ADR jika keputusan: sulit dibatalkan, memengaruhi banyak modul, atau memilih di antara
beberapa alternatif yang masuk akal. Contoh: zona waktu penyimpanan, strategi idempotency, pilihan
package.

- Template: [`docs/templates/adr.md`](../templates/adr.md).
- Nama: `docs/adr/0001-timezone-utc.md` (nomor urut 4 digit).
- Status: `Diusulkan` → `Diterima` / `Ditolak` → (`Digantikan oleh 00NN`).
- ADR yang sudah diterima tidak diedit isinya; buat ADR baru yang menggantikan.

## 7. Template

| Template | Untuk |
|---|---|
| [`feature-spec.md`](../templates/feature-spec.md) | Spesifikasi fitur sebelum dikerjakan |
| [`endpoint.md`](../templates/endpoint.md) | Dokumentasi satu endpoint di `docs/api/{domain}.md` |
| [`adr.md`](../templates/adr.md) | Keputusan arsitektur |
| [`component.md`](../templates/component.md) | Standar komponen UI baru |
| `.github/pull_request_template.md` | Deskripsi PR |
