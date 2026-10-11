<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier member M5 (ADR 0013, Q64): tingkatan berdasarkan total belanja 12 bulan bergulir, pengali poin & diskon
 * tambahan. Member di bawah tier terendah = tanpa tier (tier_id null). Order menyimpan snapshot nama tier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_tiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            // Batas total belanja 12 bulan bergulir (order selesai, tanpa void)
            $table->decimal('min_spend_12m', 15, 2)->default(0);
            $table->decimal('point_multiplier', 5, 2)->default(1);
            // Ditambahkan ke diskon member dasar (member_settings.member_discount_percent)
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'min_spend_12m']);
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->index(['tenant_id', 'tier_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('member_tier', 50)->nullable()->after('member_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('member_tier'));
        Schema::table('members', fn (Blueprint $table) => $table->dropIndex(['tenant_id', 'tier_id']));
        Schema::dropIfExists('member_tiers');
    }
};
