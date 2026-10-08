# Dokumentasi gs-point-of-sale-platform

Mulai dari [`AGENTS.md`](../AGENTS.md) di root project.

```
docs/
├── SPEC.md                    Spesifikasi produk — sumber kebenaran
├── standards/                 Acuan wajib
│   ├── project-structure.md   Lokasi file & penamaan
│   ├── coding.md              Gaya PHP/Laravel, Action, uang, enum
│   ├── testing.md             Jenis test & 5 kasus wajib
│   ├── workflow.md            Issue → branch → commit → PR → rilis
│   ├── documentation.md       Cara menulis dokumen & ADR
│   ├── api/                   Konvensi, envelope, error code, auth, idempotency, pola kode
│   └── ui/                    Token desain, tema Filament, layout, konten, komponen
├── api/                       Dokumentasi endpoint per domain
├── features/                  Spesifikasi fitur (opsional)
├── adr/                       Keputusan arsitektur
└── templates/                 Template: fitur, endpoint, ADR, komponen
```

## Urutan baca untuk anggota baru

1. `docs/SPEC.md` — Ringkasan, Arsitektur, Role & permission, Aturan bisnis
2. `AGENTS.md`
3. `docs/standards/project-structure.md` dan `coding.md`
4. Standar sesuai tugas: `api/` (backend), `ui/` (panel)
