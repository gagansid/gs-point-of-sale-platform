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
| [0009](0009-onboarding-and-editions.md) | Onboarding daftar sendiri + sales, edisi SaaS & jual putus | Diterima |
| [0010](0010-multi-outlet.md) | Multi-outlet: penugasan banyak-ke-banyak, katalog bersama, stok per outlet | Diterima |
| [0011](0011-per-outlet-catalog-and-payment-methods.md) | Menu & metode bayar per outlet: katalog bersama, dipilih per outlet, harga sama | Diterima |
| [0012](0012-pdf-export-dompdf.md) | Export laporan PDF dengan dompdf (PHP murni, tanpa Chrome/biner) | Diterima |
| [0013](0013-member-loyalty-program.md) | Program member milik bisnis: poin sebagai metode bayar, tier, stempel, voucher, halaman member HP + kata sandi | Diterima |
| [0014](0014-table-qr-self-order-and-midtrans.md) | Self-order QR meja: terima kasir dulu, bayar dulu, Midtrans per owner | Diterima, diubah sebagian oleh 0027 |
| [0015](0015-stock-in-opname-transfer.md) | Stok masuk, opname, transfer antar outlet: produk jadi, modal rata-rata tertimbang | Diterima |
| [0016](0016-web-installer.md) | Installer lewat form web: cek syarat, database, akun, progress, kunci setelah instal | Diterima |
| [0017](0017-shared-shift-cash-handover.md) | Shift bersama per perangkat: ganti kasir tanpa hitung, setoran, carry-over kas awal, kas masuk/keluar | Diterima |
| [0018](0018-partial-refund.md) | Retur sebagian per item: nilai proporsional, tunai dari laci / manual, poin & stok ikut | Diterima |
| [0019](0019-split-merge-open-bill.md) | Pecah & gabung open bill: tanpa pembayaran/diskon, dihitung ulang, status merged | Diterima |
| [0020](0020-recipes-and-bundles.md) | Bahan baku, resep, opsi berbahan, paket: stok dipotong dari komponen, HPP dari modal bahan | Diterima |
| [0021](0021-purchase-orders.md) | Purchase order ke supplier: kirim, terima sebagian lewat Stok masuk, batal | Diterima |
| [0022](0022-automatic-promotions.md) | Promo otomatis: diskon produk, beli X gratis Y, minimal belanja, terjadwal; dihitung sebelum diskon manual | Diterima |
| [0023](0023-customer-credit.md) | Kasbon member: bukti QR/PIN, batas kredit, void/retur, pelunasan masuk kas shift | Diterima |
| [0024](0024-subscription-plans-billing.md) | Paket langganan & tagihan SaaS: fitur per paket, Midtrans vendor / manual, pengingat | Diterima |
| [0025](0025-plan-features-quotas-addons.md) | Katalog fitur, kuota, dan add-on paket langganan; gerbang fitur di API | Diterima |
| [0026](0026-tenant-role-permissions.md) | Hak akses role per bisnis: owner mengatur permission manager/supervisor/kasir, `dashboard.access` | Diterima |
| [0027](0027-self-order-pay-first-and-delivery.md) | Pesan dari meja: bayar dulu tanpa terima kasir, QRIS di halaman, login member, antrean sampai diantar | Diterima |
