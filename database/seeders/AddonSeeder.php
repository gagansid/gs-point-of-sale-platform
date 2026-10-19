<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Billing\SaveAddon;
use App\Models\Addon;
use App\Support\Edition;
use Illuminate\Database\Seeder;

/**
 * Katalog add-on bawaan (ADR 0025 C1): satu add-on fitur per fitur berbayar + tambahan kuota per jenis kuota.
 * Aman dijalankan berulang & di production (`php artisan db:seed --class=AddonSeeder`): add-on dengan kode yang sudah
 * ada TIDAK diubah. Harga tahunan = 10 × bulanan (2 bulan gratis). Edisi jual putus tidak memakai add-on.
 */
final class AddonSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function addons(): array
    {
        $feature = fn (string $code, string $key, string $name, int $monthly, string $description, int $sort): array => [
            'code' => $code, 'name' => $name, 'type' => Addon::TYPE_FEATURE, 'feature_key' => $key,
            'price_monthly' => (string) $monthly, 'price_yearly' => (string) ($monthly * 10),
            'description' => $description, 'sort_order' => $sort,
        ];
        $quota = fn (string $code, string $key, int $amount, string $name, int $monthly, string $description, int $sort): array => [
            'code' => $code, 'name' => $name, 'type' => Addon::TYPE_QUOTA, 'quota_key' => $key, 'quota_amount' => $amount,
            'price_monthly' => (string) $monthly, 'price_yearly' => (string) ($monthly * 10),
            'description' => $description, 'sort_order' => $sort,
        ];

        return [
            // Tambahan kuota
            $quota('extra-outlet', 'outlets', 1, 'Outlet tambahan', 99000, 'Tambah 1 outlet/cabang aktif di atas batas paket', 10),
            $quota('extra-users', 'users', 5, 'Karyawan tambahan', 25000, 'Tambah 5 akun karyawan aktif', 20),
            $quota('extra-device', 'devices', 1, 'Perangkat kasir tambahan', 39000, 'Tambah 1 tablet/HP kasir terdaftar', 30),
            $quota('extra-products', 'products', 500, 'Produk tambahan', 25000, 'Tambah 500 produk aktif', 40),
            $quota('extra-members', 'members', 1000, 'Member tambahan', 25000, 'Tambah 1.000 member aktif', 50),
            // Fitur di luar paket
            $feature('addon-member', 'member', 'Program member', 49000, 'Poin, tier, diskon member, dan voucher ulang tahun', 110),
            $feature('addon-promotions', 'promotions', 'Promo otomatis', 39000, 'Diskon otomatis per jam/hari/produk dan voucher', 120),
            $feature('addon-stock', 'stock', 'Stok lanjutan', 49000, 'Stok masuk, supplier, opname, transfer antar outlet, PO', 130),
            $feature('addon-recipes', 'recipes', 'Bahan baku & resep', 49000, 'Stok bahan baku berkurang otomatis sesuai resep menu', 140),
            $feature('addon-self-order', 'self_order', 'Self-order QR meja', 99000, 'Pelanggan memesan & membayar dari QR di meja', 150),
            $feature('addon-credit', 'credit', 'Kasbon member', 29000, 'Belanja sekarang bayar nanti untuk member, dengan batas', 160),
            $feature('addon-adv-reports', 'advanced_reports', 'Laporan lanjutan', 39000, 'Laporan per jam, per karyawan, margin, dan perbandingan periode', 170),
            $feature('addon-report-export', 'report_export', 'Export laporan', 19000, 'Unduh laporan ke Excel/CSV', 180),
            $feature('addon-web-cashier', 'web_cashier', 'Kasir web', 29000, 'Kasir lewat browser di laptop/tablet tanpa aplikasi', 190),
            $feature('addon-online-payment', 'online_payment', 'Pembayaran online', 49000, 'QRIS & e-wallet lewat payment gateway', 200),
        ];
    }

    public function run(): void
    {
        if (Edition::isSelfHosted()) {
            return;
        }

        foreach (self::addons() as $addon) {
            if (Addon::query()->where('code', $addon['code'])->exists()) {
                continue;
            }

            app(SaveAddon::class)->handle(null, $addon);
        }
    }
}
