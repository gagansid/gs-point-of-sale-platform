# ADR 0005: Tenant scope fail-closed & Gate hanya untuk permission

- Status: Diterima
- Tanggal: 2026-10-08
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC "Multi-tenant", "Role & permission"; `docs/standards/security.md`

## Konteks

Contoh kode di SPEC punya dua celah:

1. `BelongsToTenant` hanya memfilter **jika** tenant context ada. Jika middleware lupa dipasang,
   query mengembalikan data **semua tenant** (fail-open) — kebocoran data lintas bisnis.
2. `Gate::before(fn ($user, $ability) => $user->role->allows($ability) ?: null)` dengan owner = `['*']`
   membuat owner lolos **semua** ability, termasuk ability Policy (`update`, `delete`). Aturan bisnis
   di Policy (mis. order yang sudah ditutup tidak boleh diubah) ikut terlewati.

## Keputusan

**Tenancy fail-closed**

- `TenantScope`: tanpa tenant context → `WHERE 1 = 0` (tidak ada data).
- Lintas tenant hanya lewat scope eksplisit `Model::allTenants()` / `Model::forTenant($id)`
  (panel `/admin`, command sistem).
- `creating`: `tenant_id` wajib ada dan sama dengan tenant aktif. `updating`: `tenant_id` tidak boleh berubah.
- `TenantContext` adalah scoped singleton (reset per request/job) dan dicatat di `Context`
  sehingga job queue berjalan di tenant yang sama.
- Middleware `tenant` (`SetTenantContext`) berjalan setelah auth dan **sebelum** route model binding.

**Permission**

- `Gate::before` hanya memutuskan ability yang terdaftar di `UserRole::PERMISSIONS`;
  hasilnya `true`/`false` dari role (Gate/Policy lain tidak bisa menambah permission).
- Ability lain dikembalikan `null` → diputuskan Policy, yang memanggil `$user->can('product.manage')` dsb.
- Wildcard owner `*` hanya berlaku di dalam daftar permission.

## Konsekuensi

- Seeder, factory, dan command wajib memakai `TenantContext::run($tenantId, ...)` atau mengisi `tenant_id`.
- Permission baru wajib ditambahkan ke `UserRole::PERMISSIONS` dan tabel SPEC (dicek test `UserRoleTest`).
- Test `BelongsToTenantTest` dan `SetTenantContextTest` menjadi pagar regresi isolasi tenant.
