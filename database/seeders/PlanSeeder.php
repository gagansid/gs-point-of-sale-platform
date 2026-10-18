<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Billing\SavePlan;
use App\Models\Plan;
use App\Support\Edition;
use Illuminate\Database\Seeder;

/**
 * Paket langganan bawaan (ADR 0024/0025): Basic, Pro, Bisnis (publik) dan Enterprise (khusus, ditawarkan admin).
 * Aman dijalankan berulang & di production (`php artisan db:seed --class=PlanSeeder`): paket dengan kode yang sudah
 * ada TIDAK diubah, sehingga harga/fitur yang diatur admin tidak tertimpa. Edisi jual putus tidak memakai paket.
 *
 * Harga tahunan = 10 × bulanan (2 bulan gratis). Kuota kosong = tanpa batas.
 */
final class PlanSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function plans(): array
    {
        return [
            [
                'code' => 'basic', 'name' => 'Basic', 'sort_order' => 10,
                'description' => 'Untuk usaha kecil satu kasir: transaksi, shift, stok sederhana, dan laporan dasar',
                'price_monthly' => '99000', 'price_yearly' => '990000',
                'limits' => ['outlets' => 1, 'users' => 3, 'devices' => 1, 'products' => 100],
                'features' => [],
            ],
            [
                'code' => 'pro', 'name' => 'Pro', 'sort_order' => 20,
                'description' => 'Untuk kafe yang ramai: member & voucher, promo otomatis, stok lanjutan, laporan lanjutan, kasir web',
                'price_monthly' => '199000', 'price_yearly' => '1990000',
                'limits' => ['outlets' => 1, 'users' => 10, 'devices' => 3, 'products' => 500, 'members' => 2000],
                'features' => ['member', 'promotions', 'stock', 'recipes', 'advanced_reports', 'report_export', 'web_cashier'],
            ],
            [
                'code' => 'bisnis', 'name' => 'Bisnis', 'sort_order' => 30,
                'description' => 'Untuk usaha multi-cabang: semua fitur termasuk self-order QR meja, pembayaran online, dan kasbon',
                'price_monthly' => '399000', 'price_yearly' => '3990000',
                'limits' => ['outlets' => 3, 'users' => 30, 'devices' => 10, 'products' => 2000, 'members' => 10000],
                'features' => ['member', 'self_order', 'stock', 'recipes', 'promotions', 'credit', 'advanced_reports', 'report_export', 'web_cashier', 'online_payment'],
            ],
            [
                'code' => 'enterprise', 'name' => 'Enterprise', 'sort_order' => 40, 'is_public' => false,
                'description' => 'Paket khusus untuk jaringan/waralaba: tanpa batas, harga sesuai kesepakatan (ditagihkan admin)',
                'price_monthly' => '999000', 'price_yearly' => '9990000',
                'limits' => [],
                'features' => ['member', 'self_order', 'stock', 'recipes', 'promotions', 'credit', 'advanced_reports', 'report_export', 'web_cashier', 'online_payment'],
            ],
        ];
    }

    public function run(): void
    {
        if (Edition::isSelfHosted()) {
            return;
        }

        foreach (self::plans() as $plan) {
            if (Plan::query()->where('code', $plan['code'])->exists()) {
                continue;
            }

            app(SavePlan::class)->handle(null, $plan);
        }
    }
}
