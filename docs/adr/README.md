# Architecture Decision Records

Keputusan arsitektur yang sudah diambil. Cara menulis: `docs/standards/documentation.md` §6,
template: `docs/templates/adr.md`.

| No | Judul | Status |
|---|---|---|
| [0001](0001-timezone-utc.md) | Waktu disimpan UTC, tampilan & nomor order memakai zona outlet | Diterima |
| [0002](0002-device-token.md) | Device kasir memiliki token sendiri | Diterima |
| [0003](0003-branch-feature-pos.md) | Pengerjaan MVP di branch `feature/pos` | Diterima |
| [0004](0004-php-82-laravel-12.md) | PHP 8.2 dengan Laravel 12 | Diterima |
| [0005](0005-fail-closed-tenancy-and-permission-gate.md) | Tenant scope fail-closed & Gate hanya untuk permission | Diterima |
| [0006](0006-admin-2fa-toggle.md) | 2FA super admin yang bisa diaktifkan/dinonaktifkan dari panel | Diterima |
| [0007](0007-dashboard-tenancy-without-filament-tenancy.md) | Panel /dashboard memakai TenantContext, bukan tenancy Filament | Diterima |
| [0008](0008-subdomain-per-surface.md) | Subdomain terpisah: gspos.id, app., admin., api. | Diterima |
