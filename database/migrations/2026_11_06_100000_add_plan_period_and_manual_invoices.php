<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rapikan admin 2026-10-19:
 * - plans.available_from/available_until: periode paket bisa dipilih owner (mis. promo); kosong = tanpa batas.
 * - Tagihan manual admin (kind = manual): tanpa paket (plan_* nullable), judul, diskon, PPN, catatan untuk pelanggan.
 *   Baris jenis `custom` = biaya bebas (instalasi, pelatihan, perangkat, dst.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->timestamp('available_from')->nullable()->after('is_public');
            $table->timestamp('available_until')->nullable()->after('available_from');
        });

        // plan_id (FK): ubah nullable TANPA mengganti tipenya. `uuid()->change()` di MariaDB 10.7+ menjadi tipe
        // UUID native, sedangkan plans.id bisa char(36) (mis. hasil dump) → FK tidak cocok (errno 150).
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $type = (string) DB::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'subscription_invoices')->where('COLUMN_NAME', 'plan_id')
                ->value('COLUMN_TYPE');
            DB::statement("ALTER TABLE `subscription_invoices` MODIFY `plan_id` {$type} NULL");
        }

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->uuid('plan_id')->nullable()->change();
            }
            $table->string('plan_name', 60)->nullable()->change();
            $table->string('billing_cycle', 10)->nullable()->change();
            $table->string('title', 120)->nullable()->after('kind');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('amount');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('discount_amount');
            $table->decimal('tax_amount', 15, 2)->default(0)->after('tax_rate');
            $table->string('memo', 1000)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropColumn(['title', 'discount_amount', 'tax_rate', 'tax_amount', 'memo']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['available_from', 'available_until']);
        });
    }
};
