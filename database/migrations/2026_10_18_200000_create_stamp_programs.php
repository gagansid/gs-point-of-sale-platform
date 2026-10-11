<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kartu stempel M6 (ADR 0013, Q65): program per produk/kategori terpilih, 1 item = 1 stempel, N stempel = 1 item
 * gratis (item termurah yang memenuhi). Mutasi stempel append-only seperti ledger poin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stamp_programs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('stamps_required');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('stamp_program_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stamp_program_id')->constrained()->cascadeOnDelete();
            // Salah satu terisi: produk tertentu atau semua produk di kategori
            $table->foreignUuid('product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['stamp_program_id', 'product_id']);
            $table->unique(['stamp_program_id', 'category_id']);
        });

        Schema::create('member_stamps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stamp_program_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            // earn, redeem, void_reversal, adjust (pola PointEntryType)
            $table->string('type', 20);
            $table->integer('change');
            $table->integer('balance_after');
            $table->string('reason', 255)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'stamp_program_id']);
            $table->index(['order_id', 'type']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('stamp_free_total', 15, 2)->default(0)->after('member_discount');
            // Gratis stempel yang dipakai: [{stamp_program_id, name, product_id, product_name, amount}]
            $table->json('stamp_redemptions')->nullable()->after('stamp_free_total');
            $table->unsignedInteger('stamps_earned')->default(0)->after('points_redeemed');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['stamp_free_total', 'stamp_redemptions', 'stamps_earned']));
        Schema::dropIfExists('member_stamps');
        Schema::dropIfExists('stamp_program_items');
        Schema::dropIfExists('stamp_programs');
    }
};
