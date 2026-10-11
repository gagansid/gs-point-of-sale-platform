<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Target omzet per outlet (Q56, R6): harian & bulanan, null = tidak memakai target.
 * Progres tampil di Beranda dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->decimal('daily_sales_target', 15, 2)->nullable()->after('discount_limits');
            $table->decimal('monthly_sales_target', 15, 2)->nullable()->after('daily_sales_target');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn(['daily_sales_target', 'monthly_sales_target']);
        });
    }
};
