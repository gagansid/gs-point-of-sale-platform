# Catatan perubahan untuk aplikasi kasir (Flutter)

Daftar perubahan di platform yang **perlu diterapkan di aplikasi** `gs-point-of-sale-app`. Kontrak lengkap tetap di
file domain masing-masing; dokumen ini hanya merangkum apa yang harus diubah, urut dari yang paling berdampak.

> Diperbarui: 2026-10-23 · Status aplikasi: **belum diterapkan** (repo aplikasi tidak ada di mesin platform).
> Tandai ✅ di kolom Status setelah dirilis di aplikasi.

| # | Perubahan | Wajib? | Rujukan | Status |
|---|---|---|---|---|
| F1 | Hak akses dari `/auth/me` per bisnis & per karyawan | Wajib | ADR 0026, SPEC Q133–Q134, [`auth.md`](auth.md) | ⏳ |
| F2 | Pratinjau total: pembulatan **selalu ke atas** | Wajib | [`order.md`](order.md) §Aturan perhitungan 1 | ⏳ |
| F3 | Opsi bawaan per grup (`options[].is_default`) | Disarankan | SPEC Q129, [`product.md`](product.md) | ⏳ |

---

## F1. Hak akses mengikuti pengaturan owner (ADR 0026)

**Yang berubah.** Owner kini bisa mengubah izin role Manager/Supervisor/Kasir per bisnis (Pengaturan → Hak akses) dan
menambah/mencabut izin satu karyawan. `GET /v1/auth/me` (dan respons login) → `data.user.permissions` sudah berisi
izin yang **berlaku**; kontrak JSON tidak berubah.

**Yang harus dilakukan aplikasi.**

1. Jangan pernah menurunkan izin dari `role` (mis. "kasir tidak boleh void"). Selalu baca `user.permissions`.
2. Muat ulang `/auth/me` saat aplikasi kembali ke depan (resume) dan setelah login PIN, lalu perbarui menu/tombol.
3. Bila server menjawab `403 FORBIDDEN` atau `403 APPROVAL_REQUIRED` untuk aksi yang tadinya boleh: tampilkan pesan
   `message` dari server, muat ulang `/auth/me`, sembunyikan aksinya. Jangan menganggapnya error jaringan.
4. Abaikan izin yang tidak dikenal aplikasi (mis. `dashboard.access`, `access.manage` — khusus dashboard web).

**Peta izin → layar/aksi aplikasi.**

| Izin | Tampilkan / izinkan | Tanpa izin |
|---|---|---|
| `order.create` | Layar transaksi, checkout, open bill | Sembunyikan transaksi |
| `order.view_own` / `order.view_all` | Riwayat order shift sendiri / semua shift | — / filter hanya shift sendiri |
| `order.reprint` | Tombol cetak ulang struk | Sembunyikan |
| `order.discount` / `order.discount_over_limit` | Diskon sampai batas role / tanpa batas | Tanpa diskon / di atas batas minta PIN approver (`DISCOUNT_OVER_LIMIT`) |
| `order.void`, `order.refund` | Void / retur langsung | Tetap tampil, minta PIN approver 🔑 |
| `self_order.handle` | Pesanan meja (terima/tolak) | Sembunyikan |
| `shift.operate` | Buka/tutup shift, kas masuk/keluar | Sembunyikan |
| `shift.edit_opening_cash`, `shift.cash_out_over_limit` | Langsung | Minta PIN approver 🔑 |
| `shift.view_all`, `shift.force_close` | Daftar & tutup paksa shift lain | Sembunyikan |
| `product.toggle_available` | Tandai menu habis/tersedia | Sembunyikan |
| `product.manage`, `stock.adjust` | Kelola produk, sesuaikan stok | Sembunyikan |
| `member.view` / `member.create` / `member.points_adjust` | Cari & pasang member / daftar member / sesuaikan poin | Sembunyikan |
| `report.view_own` / `report.view_shift` / `report.view` | Rekap shift sendiri / laporan operasional ≤ 31 hari / semua laporan | Sembunyikan tab terkait |

**Uji terima.**

- [ ] Owner mencabut "Bertransaksi" dari Kasir → setelah aplikasi dibuka ulang, kasir tidak melihat layar transaksi.
- [ ] Owner memberi satu kasir "Void transaksi" → hanya kasir itu yang void tanpa PIN; kasir lain tetap diminta PIN.
- [ ] Izin dicabut saat aplikasi terbuka → aksi berikutnya mendapat `403`, pesan tampil, menu ikut diperbarui.

## F2. Pembulatan grand total selalu ke atas

Server membulatkan grand total **ke atas** ke kelipatan `outlet.rounding` (dulu tertulis half-up). Pratinjau keranjang
wajib memakai rumus yang sama dengan aritmetika desimal: `ceil(total / rounding) × rounding`, atau panggil
`POST /v1/orders/quote`. Contoh: total Rp27.910, pembulatan 100 → **Rp28.000** (bukan Rp27.900); Rp28.000 tetap
Rp28.000; `rounding = 0` = tanpa pembulatan. Jangan memakai `double` untuk uang; nominal akhir selalu dari server.

- [ ] Total keranjang = total struk server untuk 10 transaksi acak (termasuk pajak inclusive & service).

## F3. Opsi bawaan per grup

`GET /v1/catalog` → `options[].is_default: true` menandai opsi yang langsung terpilih saat produk dibuka (mis. Gula
"Normal"). Grup wajib tanpa bawaan: pilih opsi pertama yang tersedia; grup opsional tanpa bawaan: kosong. Opsi yang
sedang habis (`is_available: false`) tidak pernah dipilih otomatis.

- [ ] Membuka produk ber-grup wajib langsung menampilkan opsi bawaan terpilih; kasir tetap bisa mengganti.
