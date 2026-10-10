# Audit: tanggal dibuat & diubah

Setiap detail data (form ubah, modal ubah, halaman lihat) menampilkan kapan data dibuat dan terakhir
diubah. Tabel menyediakan kolom yang sama, **tersembunyi bawaan** (tampilkan lewat tombol pilih kolom).

| Tempat | Komponen | Tampilan |
|---|---|---|
| Modal / form satu kolom | `AuditInfo::make()` (akhir skema) | Baris kecil: `📅 Dibuat 10 Okt 2026, 14.25 oleh Budi · ✎ Diubah 2 jam lalu oleh Sari` |
| Halaman dua kolom | `AuditInfo::card()` (kolom kanan, paling bawah) | Kartu "Riwayat": label kiri, waktu kanan |
| Tabel | `...AuditColumns::make()` (akhir kolom) | "Dibuat" & "Diubah" + baris kecil "oleh {nama}", toggleable hidden |

- Kelas: `App\Filament\Shared\Schemas\AuditInfo`, `App\Filament\Shared\Columns\AuditColumns`;
  view `filament/shared/audit-info`; CSS `.gs-audit*`.
- Waktu memakai zona panel (outlet aktif, ADR 0001) — `FilamentTimezone` diisi `SetDashboardTenant`.
- Tidak tampil saat menambah data. Belum ada perubahan → "Diubah: Belum pernah".
- "Diubah" relatif ("2 jam lalu") bila < 1 hari; selebihnya tanggal asli seperti "Dibuat". Tooltip = waktu lengkap.
- Tabel yang sudah punya kolom waktu dibuat (mis. Penjualan "Waktu", Lead "Masuk") memakai
  `AuditColumns::make(created: false)`.
- Pelaku dicatat trait `App\Models\Concerns\RecordsAuthor` (`created_by`, `updated_by`, SPEC Q47): user login
  dashboard/API; untuk `tenants` & `sales_leads` super admin (`authorModel()`). Tanpa user login (job, seeder,
  CLI) = kosong, dan tidak menghapus pengubah sebelumnya. Query massal (`->update()`) tidak tercatat.
  Model baru yang punya halaman detail: tambahkan trait + kolom lewat migrasi.
