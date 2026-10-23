<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UserRole;
use Filament\Support\Icons\Heroicon;

/**
 * Label & kelompok permission untuk halaman Pengaturan → Hak akses (ADR 0026). Hanya tampilan; pemetaan bawaan tetap
 * di UserRole::permissions(). Setiap permission yang bisa didelegasikan wajib ada di salah satu kelompok (dijaga test).
 */
final class PermissionCatalog
{
    /**
     * Kelompok → [judul, ikon, keterangan, permission → [label, keterangan]].
     *
     * @return array<string, array{title: string, icon: Heroicon, description: string, permissions: array<string, array{0: string, 1: string}>}>
     */
    public static function groups(): array
    {
        return [
            'dashboard' => [
                'title' => 'Dashboard & laporan',
                'icon' => Heroicon::OutlinedChartBar,
                'description' => 'Akses panel dashboard di browser dan laporan penjualan',
                'permissions' => [
                    'dashboard.access' => ['Buka dashboard', 'Beranda dan menu dashboard sesuai hak akses lain. Tanpa ini hanya menu Laporan (bila diizinkan)'],
                    'report.view' => ['Semua laporan', 'Semua tab dan periode, termasuk laba kotor & Beranda'],
                    'report.view_shift' => ['Laporan operasional', 'Ringkasan, produk, metode bayar, karyawan, shift, diskon & void; maks. 31 hari'],
                    'report.view_own' => ['Rekap shift sendiri', 'X/Z-report shift yang ia jalankan di kasir'],
                    'report.export' => ['Export laporan', 'Unduh laporan Excel & PDF'],
                    'audit.view' => ['Riwayat perubahan', 'Siapa mengubah harga, produk, setelan, dan karyawan'],
                ],
            ],
            'transactions' => [
                'title' => 'Transaksi',
                'icon' => Heroicon::OutlinedReceiptPercent,
                'description' => 'Berjualan di aplikasi kasir & kasir web, serta menu Penjualan',
                'permissions' => [
                    'order.create' => ['Bertransaksi', 'Checkout, open bill, tambah pembayaran'],
                    'order.view_own' => ['Lihat transaksi sendiri', 'Transaksi di shift yang ia jalankan'],
                    'order.view_all' => ['Lihat semua transaksi', 'Menu Penjualan & Pesanan meja, semua shift'],
                    'order.reprint' => ['Cetak ulang struk', 'Struk transaksi yang sudah selesai'],
                    'order.discount' => ['Beri diskon', 'Sampai batas diskon role di Profil outlet'],
                    'order.discount_over_limit' => ['Diskon tanpa batas', 'Melebihi batas tanpa PIN approval'],
                    'order.void' => ['Void transaksi', 'Membatalkan transaksi; tanpa ini butuh PIN approval'],
                    'order.refund' => ['Retur', 'Retur sebagian per item; tanpa ini butuh PIN approval'],
                    'self_order.handle' => ['Tangani pesanan meja', 'Terima & tolak pesanan dari QR meja'],
                    'order.fulfill' => ['Antrean pesanan', 'Majukan pesanan meja: disiapkan, siap, diantar'],
                ],
            ],
            'shifts' => [
                'title' => 'Shift & kas',
                'icon' => Heroicon::OutlinedClock,
                'description' => 'Buka-tutup shift dan uang di laci kasir',
                'permissions' => [
                    'shift.operate' => ['Jalankan shift', 'Buka & tutup shift, kas masuk/keluar di perangkatnya'],
                    'shift.view_all' => ['Lihat semua shift', 'Menu Shift & selisih kas semua kasir'],
                    'shift.force_close' => ['Tutup paksa shift', 'Menutup shift kasir lain dari dashboard'],
                    'shift.edit_opening_cash' => ['Koreksi kas awal', 'Kas awal berbeda dari sisa shift sebelumnya tanpa PIN'],
                    'shift.cash_out_over_limit' => ['Kas keluar di atas batas', 'Tanpa PIN approval'],
                ],
            ],
            'catalog' => [
                'title' => 'Katalog & stok',
                'icon' => Heroicon::OutlinedCube,
                'description' => 'Produk, menu habis, dan dokumen stok',
                'permissions' => [
                    'product.manage' => ['Kelola produk', 'Produk, kategori, grup opsi, import Excel'],
                    'product.toggle_available' => ['Tandai menu habis', 'Habis/tersedia di outlet sendiri'],
                    'stock.adjust' => ['Sesuaikan stok', 'Penyesuaian stok & menu Riwayat stok'],
                    'stock.receive' => ['Stok masuk', 'Catat barang datang di outlet'],
                    'stock.opname' => ['Stok opname', 'Hitung fisik & samakan stok'],
                    'stock.transfer' => ['Transfer stok', 'Kirim & terima barang antar outlet'],
                    'supplier.manage' => ['Kelola supplier', 'Data supplier'],
                    'purchase.manage' => ['Purchase order', 'Pesan barang ke supplier'],
                ],
            ],
            'customers' => [
                'title' => 'Pelanggan',
                'icon' => Heroicon::OutlinedUserGroup,
                'description' => 'Member, kasbon, dan promo',
                'permissions' => [
                    'member.view' => ['Lihat member', 'Cari member, pasang ke transaksi, menu Member & Kasbon'],
                    'member.create' => ['Daftarkan member', 'Tambah member baru'],
                    'member.manage' => ['Kelola member', 'Ubah, nonaktifkan, hapus member; catat pelunasan kasbon'],
                    'member.points_adjust' => ['Sesuaikan poin', 'Tambah/kurangi poin manual dengan alasan'],
                    'member.settings' => ['Program member', 'Aturan poin, tier, kartu stempel, voucher'],
                    'promo.manage' => ['Kelola promo', 'Promo otomatis'],
                ],
            ],
            'settings' => [
                'title' => 'Pengaturan',
                'icon' => Heroicon::OutlinedCog6Tooth,
                'description' => 'Setelan toko. Pengelola karyawan selain owner hanya bisa mengatur role di bawahnya',
                'permissions' => [
                    'business.settings' => ['Bisnis & tampilan', 'Profil bisnis, logo, warna dashboard'],
                    'outlet.settings' => ['Profil outlet', 'Pajak, service, pembulatan, struk, batas diskon'],
                    'outlet.manage' => ['Tambah & nonaktifkan outlet', 'Mengikuti kuota paket'],
                    'user.manage' => ['Kelola karyawan', 'Tambah, ubah, PIN, nonaktifkan karyawan'],
                    'device.manage' => ['Kelola perangkat', 'Daftarkan & cabut akses tablet kasir'],
                    'payment_method.manage' => ['Metode pembayaran', 'Aktifkan metode bayar per outlet'],
                    'payment_gateway.manage' => ['Pembayaran online', 'Hubungkan akun Midtrans bisnis'],
                    'table.manage' => ['Meja & QR', 'Meja, QR, dan setelan pesan dari meja'],
                ],
            ],
        ];
    }

    /** Label permission ("Void transaksi"); permission tidak dikenal = kodenya. */
    public static function label(string $permission): string
    {
        foreach (self::groups() as $group) {
            if (isset($group['permissions'][$permission])) {
                return $group['permissions'][$permission][0];
            }
        }

        return match ($permission) {
            'billing.manage' => 'Langganan',
            'access.manage' => 'Hak akses',
            'outlet.access_all' => 'Semua outlet',
            default => $permission,
        };
    }

    /** @return list<string> permission di semua kelompok, urut tampilan */
    public static function permissions(): array
    {
        return array_merge(...array_map(fn (array $g): array => array_keys($g['permissions']), array_values(self::groups())));
    }

    /**
     * Role yang bisa diatur, urut tampilan tab.
     *
     * @return list<UserRole>
     */
    public static function roles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role): bool => $role->isConfigurable()));
    }
}
