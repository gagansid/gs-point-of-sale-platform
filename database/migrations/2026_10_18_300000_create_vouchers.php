<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voucher M7 (ADR 0013, Q66): kode promo owner & voucher ulang tahun pribadi (member_id, tanpa kode). Pemakaian
 * dicatat per order (voucher_redemptions) saat order selesai; void mengembalikan kuota.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            // Huruf besar; null untuk voucher pribadi (ulang tahun)
            $table->string('code', 30)->nullable();
            $table->string('name', 100);
            // owner (kode promo) / birthday
            $table->string('source', 20)->default('owner');
            // fixed / percent (DiscountType)
            $table->string('type', 10);
            $table->decimal('value', 15, 2);
            $table->decimal('max_discount', 15, 2)->nullable();
            $table->decimal('min_spend', 15, 2)->default(0);
            // null = tanpa batas
            $table->unsignedInteger('quota')->nullable();
            $table->unsignedInteger('per_member_quota')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('member_only')->default(false);
            // null = semua tier / semua outlet
            $table->json('tier_ids')->nullable();
            $table->json('outlet_ids')->nullable();
            // Voucher pribadi (ulang tahun)
            $table->foreignUuid('member_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'member_id']);
            $table->index(['tenant_id', 'source', 'starts_at']);
        });

        Schema::create('voucher_redemptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 15, 2);
            // used / voided
            $table->string('status', 10)->default('used');
            $table->timestamps();

            // Satu voucher sekali per order (idempotent)
            $table->unique(['voucher_id', 'order_id']);
            $table->index(['voucher_id', 'member_id', 'status']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUuid('voucher_id')->nullable()->after('stamp_redemptions')->constrained()->nullOnDelete();
            // Snapshot kode / nama voucher saat transaksi
            $table->string('voucher_code', 100)->nullable()->after('voucher_id');
            $table->decimal('voucher_discount', 15, 2)->default(0)->after('voucher_code');
        });

        Schema::table('member_settings', function (Blueprint $table): void {
            // {enabled, type, value, max_discount, min_spend}
            $table->json('birthday_voucher')->nullable()->after('member_discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('member_settings', fn (Blueprint $table) => $table->dropColumn('birthday_voucher'));
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voucher_id');
            $table->dropColumn(['voucher_code', 'voucher_discount']);
        });
        Schema::dropIfExists('voucher_redemptions');
        Schema::dropIfExists('vouchers');
    }
};
