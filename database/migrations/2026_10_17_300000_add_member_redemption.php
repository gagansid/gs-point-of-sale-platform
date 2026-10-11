<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member M3 (ADR 0013, Q62–Q63): diskon member % (setelah diskon order manual), poin ditukar per order
 * (pembayaran kategori `points`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_settings', function (Blueprint $table): void {
            $table->decimal('member_discount_percent', 5, 2)->default(0)->after('expiry_months');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('member_discount', 15, 2)->default(0)->after('order_discount');
            $table->unsignedInteger('points_redeemed')->default(0)->after('points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['member_discount', 'points_redeemed']));
        Schema::table('member_settings', fn (Blueprint $table) => $table->dropColumn('member_discount_percent'));
    }
};
