<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->uuid('plan_id')->nullable()->change();
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
