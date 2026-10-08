# ADR 0003: Pengerjaan MVP di branch `feature/pos`

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q9

## Konteks

SPEC memakai model `main` (production) + `develop` (staging) + branch pendek per pekerjaan.
Repo saat ini memakai default branch `master`, belum punya `develop`, dan pekerjaan sudah dimulai
di `feature/pos`.

## Keputusan

- Selama fase MVP, semua pekerjaan dilakukan dan di-commit di branch **`feature/pos`**.
- Tidak membuat `develop` dan tidak me-rename `master` untuk saat ini.
- Format commit (Conventional Commits + scope) tetap berlaku penuh.
- `master` tidak di-commit langsung; penggabungan `feature/pos` → `master` lewat PR saat siap rilis.

## Konsekuensi

- Bagian branch di `docs/standards/workflow.md` menjadi target jangka panjang. Saat model
  `main`/`develop` diterapkan, ADR ini digantikan oleh ADR baru.
- Karena tidak ada PR per fitur, setiap commit harus kecil, lulus test, dan bisa dibaca sebagai satu unit pekerjaan.
