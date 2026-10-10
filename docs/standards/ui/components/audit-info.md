# Audit: tanggal dibuat & diubah

Setiap detail data (form ubah, modal ubah, halaman lihat) menampilkan kapan data dibuat dan terakhir
diubah. Tabel menyediakan kolom yang sama, **tersembunyi bawaan** (tampilkan lewat tombol pilih kolom).

| Tempat | Komponen | Tampilan |
|---|---|---|
| Modal / form satu kolom | `AuditInfo::make()` (akhir skema) | Baris kecil: `📅 Dibuat 10 Okt 2026, 14.25 · ✎ Diubah 2 jam lalu` |
| Halaman dua kolom | `AuditInfo::card()` (kolom kanan, paling bawah) | Kartu "Riwayat": label kiri, waktu kanan |
| Tabel | `...AuditColumns::make()` (akhir kolom) | "Dibuat" (tanggal) & "Diubah" (relatif + tooltip), toggleable hidden |

- Kelas: `App\Filament\Shared\Schemas\AuditInfo`, `App\Filament\Shared\Columns\AuditColumns`;
  view `filament/shared/audit-info`; CSS `.gs-audit*`.
- Waktu memakai zona panel (outlet aktif, ADR 0001) — `FilamentTimezone` diisi `SetDashboardTenant`.
- Tidak tampil saat menambah data. Belum ada perubahan → "Belum pernah diubah".
- Tabel yang sudah punya kolom waktu dibuat (mis. Penjualan "Waktu", Lead "Masuk") memakai
  `AuditColumns::make(created: false)`.
- Siapa yang membuat/mengubah belum dicatat (kolom `created_by`/`updated_by` belum ada).
