# ADR 0026: Hak akses role per bisnis

- Status: Diterima
- Tanggal: 2026-10-22
- Pengambil keputusan: Gagan Suganda (pilihan A, daftar delegasi & akses dashboard disetujui 2026-10-22)
- Terkait: ADR 0005 (permission, bukan role), ADR 0009 (hanya-baca), ADR 0010 (outlet), SPEC Q46, Q50, Q79, Q133

## Konteks

Pemetaan role → permission tertanam di `UserRole::permissions()`, sama untuk semua bisnis. Owner tidak bisa
mengatur, mis., manager tanpa akses Laporan, manager boleh mengurus Voucher, atau supervisor boleh melihat Penjualan.
Selain itu akses panel `/dashboard` memakai saklar berbasis role (`UserRole::hasFullDashboard()` = punya `report.view`)
di ±15 menu, sehingga memberi permission saja tidak akan memunculkan menu.

Pilihan yang ditimbang:

| Pilihan | Ringkas | Diputuskan |
|---|---|---|
| **A. Matriks per role, diatur owner** | 4 role tetap; owner mencentang permission manager/supervisor/kasir | ✅ |
| B. A + pengecualian per karyawan | Fleksibel; diaudit lewat Riwayat perubahan & badge "Hak akses khusus" | ✅ (tambahan 2026-10-22) |
| C. Role kustom | Mengubah enum role di API, Flutter, login PIN/kata sandi | ❌ |

## Keputusan

| Topik | Keputusan |
|---|---|
| Penyimpanan | Tabel `role_permissions` (`tenant_id`, `role`, `permissions` JSON, unik per tenant+role). Tanpa baris = bawaan `UserRole::permissions()` |
| Membaca | `App\Support\RolePermissions` (cache statis per tenant per request, seperti `Features`) dipakai `User::hasPermission()`, `Gate::before`, `User::grantedPermissions()` (`/auth/me`). `UserRole::allows()` = bawaan saja |
| Menulis | Action `App\Actions\User\UpdateRolePermissions` (izin `access.manage`); `null` = kembali ke bawaan. Berlaku di request berikutnya tanpa logout |
| Owner | Selalu semua permission; tidak bisa diubah |
| Khusus owner (`UserRole::OWNER_ONLY`) | `billing.manage` (langganan), `access.manage` (hak akses itu sendiri — cegah naik hak), `outlet.access_all` (struktur penugasan outlet). Tidak berlaku untuk role lain walau tersimpan di DB |
| Bisa didelegasikan | Semua permission lain, termasuk `user.manage`, `outlet.manage`, `business.settings`, `payment_gateway.manage` |
| Permission tersirat | `UserRole::IMPLIES`: mis. `member.manage` → `member.view`, `report.view` → `report.view_shift` + `report.view_own`, `order.view_all` → `order.view_own` |
| Akses dashboard | Permission baru **`dashboard.access`** menggantikan `hasFullDashboard()`. Masuk panel = `dashboard.access` **atau** `report.view_shift` (Laporan saja). Setiap menu = `dashboard.access` + permission menu, kecuali Laporan dan Stok masuk (Q50, Q79). Bawaan: owner & manager punya; supervisor & kasir tidak (owner bisa memberi) |
| Beranda | Butuh `dashboard.access` + `report.view`; tanpa itu diarahkan ke Laporan atau menu pertama yang boleh dibuka |
| Delegasi `user.manage` | Non-owner hanya mengelola & memberi role **di bawah dirinya** (`UserRole::rank()`): tidak bisa menyentuh owner, sesama manager, atau dirinya sendiri (`SaveEmployee::ensureCanManage`, `UserPolicy::update`) → `FORBIDDEN` |
| Verifikasi email | Supervisor/kasir yang diberi akses dashboard tetap wajib email terverifikasi (Q46), berdasarkan cara login role |
| API | Policy (`ProductPolicy`, `UserPolicy`, …) tetap hanya memakai permission, tanpa `dashboard.access`, agar aplikasi kasir tidak ikut tertutup |

### Pengecualian per karyawan (opsi B, 2026-10-22)

| Topik | Keputusan |
|---|---|
| Penyimpanan | `users.permissions_granted` & `users.permissions_revoked` (JSON, NULL = ikuti role): **selisih** terhadap hak akses role di bisnis itu, sehingga perubahan role tetap berlaku untuk izin lain |
| Hasil | Hak akses role + tambahan (dilengkapi IMPLIES) − dicabut; izin yang dasarnya dicabut ikut gugur, berantai (`RolePermissions::applyOverrides`). Owner tidak terpengaruh; izin khusus owner tidak pernah bisa ditambahkan |
| Mengatur | Action `SetEmployeePermissions`, hanya `access.manage` (owner) — pengelola karyawan non-owner tidak bisa menaikkan hak akses. Sama dengan role / "Ikuti role" = pengecualian dihapus. Ganti role = pengecualian dihapus |
| UI | Karyawan → aksi **Hak akses** (modal, kartu centang sama dengan halaman Hak akses, tombol *Ikuti role*); kolom Role diberi keterangan *Hak akses khusus*; halaman Hak akses menampilkan jumlah karyawan berpengecualian per role |
| Audit | Riwayat perubahan → Karyawan: *Izin tambahan* / *Izin dicabut* berlabel Bahasa Indonesia |

## Konsekuensi

- Aturan proyek berubah: pemetaan **bawaan** tetap hanya di `UserRole::permissions()`; yang berlaku dibaca lewat
  `RolePermissions` / `$user->can()` (CLAUDE.md, AGENTS.md).
- Aplikasi Flutter tidak perlu diubah: daftar `permissions` di `/auth/me` sudah mengikuti pengaturan owner. Menu di
  aplikasi baru berubah setelah aplikasi memuat ulang `/auth/me`.
- Halaman **Pengaturan → Hak akses** (`access.manage`): tab Manager · Supervisor · Kasir (badge Bawaan/Diubah), kartu
  per kelompok menu (`App\Support\PermissionCatalog`, label Bahasa Indonesia), Pilih semua, **Kembalikan bawaan**.
  Pilihan yang sama dengan bawaan disimpan sebagai bawaan (tanpa baris). Riwayat perubahan menampilkan izin yang
  *Diberi* / *Dicabut*, bukan JSON.
