## Ringkasan

<!-- Apa yang berubah dan kenapa. Rujuk issue: Closes #123 -->

## Rujukan

- SPEC: `docs/SPEC.md#...`
- Issue: #

## Perubahan

- [ ] Migration / model
- [ ] Action / service
- [ ] Endpoint API (`docs/api/...` diperbarui)
- [ ] Filament (panel: dashboard / admin)
- [ ] Dokumentasi / standar

## Cara menguji

```bash
php artisan test --filter=...
```

<!-- Untuk UI: langkah manual + screenshot light & dark -->

## Definition of Done

- [ ] Logika bisnis di Action, dalam transaksi DB
- [ ] Permission via `can()`, tanpa cek role langsung
- [ ] Model bisnis memakai `BelongsToTenant`
- [ ] Respons envelope standar; error code dari daftar resmi
- [ ] Test: sukses, validasi, permission, isolasi tenant, request ganda
- [ ] `pint --test`, `phpstan`, `php artisan test` hijau
- [ ] UI sesuai `docs/standards/ui/` (jika ada perubahan UI)

## Catatan untuk reviewer

<!-- Risiko, keputusan yang perlu dicek, hal yang ditunda -->
