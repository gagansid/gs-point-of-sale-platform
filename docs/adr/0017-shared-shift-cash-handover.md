# ADR 0017: Shift bersama per perangkat — ganti kasir tanpa hitung, setoran, carry-over kas awal, kas masuk/keluar

- Status: Diterima
- Tanggal: 2026-10-14
- Pengambil keputusan: Gagan Suganda
- Terkait: SPEC Q87–Q91 (keputusan ini), Q16 (shift per device, direvisi), Q20 (open bill lintas shift),
  Q21 (visibilitas order, direvisi), Q49 (X/Z-report), Q54 (kasir web), ADR 0010 (multi-outlet)

## Konteks

Shift sudah satu per perangkat (Q16), tetapi dianggap milik **pembukanya**:

- Tutup biasa hanya oleh pembuka; kasir lain harus tutup paksa (`CloseShift`, kasir web `ShiftController`).
- Kasir hanya melihat order di shift yang ia buka sendiri (`VisibleOrders`, Q21).
- Kas awal selalu diketik manual.

Di kafe/UMKM, kasir sering bergantian di tengah hari (istirahat, salat, ganti jam kerja). Dengan aturan sekarang
pergantian berarti kasir A tutup shift + hitung uang, lalu kasir B buka shift + hitung lagi uang yang sama: lambat
dan menghambat antrean. Selain itu `expected_cash` hanya kas awal + penjualan tunai, sehingga uang laci yang dipakai
untuk keperluan mendadak (beli galon, tukar kembalian) muncul sebagai selisih tanpa penjelasan, dan uang yang
diambil owner setelah tutup membuat kas awal shift berikutnya tidak bisa diturunkan dari hitungan terakhir.

## Pilihan

| Topik | Opsi | Dipilih |
|---|---|---|
| Kepemilikan laci | Per kasir (hitung tiap ganti) · **per perangkat, dipakai bergantian** | **Per perangkat** |
| Ganti kasir | Tutup + buka shift · serah terima hitung sekali · **kunci layar → PIN kasir lain, tanpa hitung** | **Tanpa hitung** |
| Yang boleh tutup shift | Pembuka saja · **siapa saja yang bertugas di perangkat** · supervisor ke atas | **Siapa saja yang bertugas** |
| Kas awal | Diketik manual · **carry-over dari sisa laci shift sebelumnya** | **Carry-over**, boleh dikoreksi `shift.edit_opening_cash` |
| Uang diambil saat tutup | Tidak dicatat · **isian Disetor + modal laci tetap (opsional)** | **Disetor + modal laci tetap** |
| Kunci layar | Manual saja · **manual + otomatis setelah X menit tidak aktif** | **Manual + otomatis (bawaan 5 menit)** |

## Keputusan

### 1. Shift milik perangkat, operator bergantian

- Satu shift terbuka per perangkat tetap berlaku (`open_device_key`). `opened_by` hanya mencatat siapa yang membuka.
- **Ganti kasir**: tombol **Kunci** (selalu terlihat) atau kunci otomatis → layar PIN → kasir lain yang
  ditugaskan di outlet itu masuk. Shift, laci, dan open bill tetap; tidak ada hitung uang.
- Setiap order tetap mencatat `user_id` = operator yang sedang aktif (sudah ada).
- **Log operator** per shift (`shift_operators`: user, mulai, selesai). Baris baru saat operator berganti (login
  PIN di perangkat yang shift-nya terbuka, atau buka kunci kasir web); baris berjalan ditutup saat operator lain
  masuk atau shift ditutup. Ditampilkan di X/Z-report dan detail shift.
- **Kunci otomatis**: setelan per outlet `auto_lock_minutes` (bawaan 5, kosong = mati). Kasir web menghitung di
  browser; aplikasi Flutter memakai nilai dari `/outlet`. Kunci hanya menutup layar, sesi kasir di server tetap
  sampai operator lain masuk atau logout.

### 2. Tutup shift oleh siapa saja yang bertugas

- Tutup biasa: user dengan `shift.operate` yang sedang menjadi operator di perangkat itu (bukan hanya pembuka).
- Tutup paksa (`shift.force_close`) tetap untuk menutup dari perangkat lain/dashboard (perangkat rusak, lupa tutup).
- `closed_by` = penutup. Pesan `403` "Gunakan tutup paksa" hanya untuk penutup yang bukan operator perangkat itu.

### 3. Setoran & modal laci saat tutup

Form tutup shift:

| Isian | Aturan |
|---|---|
| Kas dihitung (`actual_cash`) | wajib, ≥ 0 (sama seperti sekarang) |
| Disetor (`cash_deposited`) | wajib, 0 ≤ disetor ≤ kas dihitung; terisi otomatis = kas dihitung − modal laci tetap bila setelan modal ada (boleh diubah) |
| Ditinggal di laci (`closing_float`) | dihitung server = kas dihitung − disetor |

- Setelan per outlet **Modal laci tetap** (`cash_float`, opsional). Kosong = isian Disetor bawaan 0.
- `difference` tetap = kas dihitung − `expected_cash`; setoran tidak memengaruhi selisih.
- Laporan Shift dan ringkasan harian menampilkan total disetor per outlet.

### 4. Carry-over kas awal

- Buka shift di perangkat yang pernah punya shift: kas awal = `closing_float` shift terakhir yang ditutup
  (biasa atau paksa) **di perangkat yang sama**. Kasir tidak menghitung dan tidak mengetik angka.
- Perangkat tanpa shift sebelumnya: kas awal diketik manual (sama seperti sekarang).
- `shifts.previous_shift_id` & `opening_cash_source` (`carry_over` / `manual`) disimpan.
- **Koreksi kas awal** saat buka shift atau selama shift berjalan: izin baru `shift.edit_opening_cash` (owner,
  manager, supervisor; kasir lewat PIN approval, approver ≠ pelaku → `SELF_APPROVAL_NOT_ALLOWED`), alasan wajib,
  nilai semula (`carried_cash`), pelaku, approver & waktu tercatat di shift dan tampil di detail shift & panel Audit.
- API `POST /shifts`: `opening_cash` menjadi opsional; bila dikirim dan berbeda dari carry-over tanpa izin →
  `403 FORBIDDEN`; bila tidak dikirim → carry-over (atau `422 VALIDATION_ERROR` untuk perangkat tanpa shift sebelumnya).

### 5. Kas masuk/keluar selama shift

- Tabel `cash_movements` (tenant, outlet, shift, user, `type` `in`/`out`, nominal > 0, alasan wajib maks. 255,
  `approved_by`, waktu); `id` dari klien = idempotency key.
- `expected_cash` = kas awal + penjualan tunai bersih + kas masuk − kas keluar.
- Hanya di shift yang masih terbuka; tidak bisa diubah/dihapus (koreksi dengan catatan lawan).
- Muncul di X/Z-report, detail shift, laporan Shift, dan panel Audit.
- Alasan: Belanja operasional (keluar), Setor ke owner (keluar), Tukar/tambah kembalian, Lainnya (catatan wajib).
- Semua `shift.operate` boleh mencatat. Kas keluar di atas `cash_out_approval_limit` outlet (bawaan Rp100.000,
  kosong = tanpa approval): izin baru `shift.cash_out_over_limit` (owner, manager, supervisor) atau PIN approval.
- Kas keluar tidak boleh melebihi kas seharusnya di laci.

### 6. Visibilitas order (revisi Q21)

Kasir melihat order di **shift yang sedang terbuka di perangkatnya** + shift yang pernah ia buka + semua open bill
(sebelumnya: hanya shift yang ia buka).

### 7. Z-report

Tambahan: rincian per operator (jumlah order, penjualan, tunai diterima), log operator, kas masuk/keluar,
disetor, ditinggal di laci. Selisih kas ditampilkan atas nama shift (tanggung jawab bersama).

## Keputusan pemilik (2026-10-14)

| # | Pertanyaan | Keputusan |
|---|---|---|
| K1 | Siapa yang boleh mencatat kas keluar? | Semua `shift.operate`; kasir butuh PIN approval bila nominal > `cash_out_approval_limit` (bawaan Rp100.000) |
| K2 | Kategori alasan kas keluar/masuk | Pilihan tetap + catatan (wajib untuk Lainnya) |

## Konsekuensi

- Positif: ganti kasir tanpa menghentikan antrean; hitung uang hanya saat tutup; kas awal tidak diketik ulang;
  selisih kas bisa dijelaskan dengan kas masuk/keluar & setoran.
- Negatif / risiko:
  - Selisih kas **tidak bisa dibebankan ke satu kasir**. Mitigasi: log operator, void/diskon tetap PIN approval,
    kas keluar tercatat & di atas batas butuh PIN, kunci otomatis.
  - Kas awal hasil carry-over tidak diverifikasi kasir berikutnya; uang yang hilang di antara tutup dan buka
    baru terlihat sebagai selisih di tutup shift berikutnya.
  - Lupa mengunci layar = transaksi tercatat atas nama operator sebelumnya (dikurangi oleh kunci otomatis).
- Yang perlu diubah: SPEC (Q16, Q21, aturan *Shift & void*, tabel permission, Q baru), `docs/api/shift.md`,
  `docs/api/settings.md` (`/outlet`), `docs/api/auth.md` (log operator saat login PIN), `UserRole::permissions()`,
  `CloseShift`, `OpenShift`, `ShiftSummary`, `VisibleOrders`, kasir web, Z-report PDF, laporan Shift, Profil outlet.
- Flutter: tombol Kunci + kunci otomatis, `opening_cash` opsional, form tutup dengan Disetor, kas masuk/keluar.

## Tahapan

| Tahap | Isi |
|---|---|
| C1 | Data & setelan outlet (`cash_float`, `auto_lock_minutes`, `cash_out_approval_limit`), tutup oleh operator mana pun, Disetor + `closing_float`, carry-over + koreksi kas awal |
| C2 | Log operator + kunci layar (manual & otomatis) di kasir web, visibilitas order per shift perangkat |
| C3 | Kas masuk/keluar (API + kasir web), `expected_cash` baru |
| C4 | X/Z-report, detail & laporan Shift, panel Audit, ringkasan harian, dokumentasi API |
