<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Poin member M2 (ADR 0013, Q62): aturan poin di member_settings, ledger append-only, poin didapat per order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_settings', function (Blueprint $table): void {
            // Kumpul: earn_points poin per earn_amount rupiah (subtotal setelah diskon, sebelum pajak/service)
            $table->decimal('earn_amount', 15, 2)->default(10000)->after('enabled');
            $table->unsignedInteger('earn_points')->default(1)->after('earn_amount');
            // Tukar (M3): 1 poin = redeem_value rupiah
            $table->decimal('redeem_value', 15, 2)->default(100)->after('earn_points');
            $table->unsignedInteger('min_redeem_points')->default(50)->after('redeem_value');
            $table->unsignedTinyInteger('max_redeem_percent')->default(50)->after('min_redeem_points');
            // 0 = tidak kedaluwarsa
            $table->unsignedSmallInteger('expiry_months')->default(12)->after('max_redeem_percent');
        });

        Schema::create('member_point_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('payment_id')->nullable();
            // earn, redeem, void_reversal, adjust, expire
            $table->string('type', 20);
            $table->integer('points');
            // Sisa poin kredit yang belum dipakai/kedaluwarsa (FIFO); 0 untuk debit
            $table->unsignedInteger('remaining')->default(0);
            $table->integer('balance_after');
            $table->timestamp('expires_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['tenant_id', 'remaining', 'expires_at']);
            $table->index(['order_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('points_earned')->default(0)->after('member_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('points_earned'));
        Schema::dropIfExists('member_point_ledger');
        Schema::table('member_settings', fn (Blueprint $table) => $table->dropColumn(['earn_amount', 'earn_points', 'redeem_value', 'min_redeem_points', 'max_redeem_percent', 'expiry_months']));
    }
};
