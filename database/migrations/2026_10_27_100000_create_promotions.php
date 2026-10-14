<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Promo otomatis (ADR 0022, Q102–Q104) + snapshot potongan promo di order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            // item_discount / buy_get / order_discount
            $table->string('type', 20);
            // percent / fixed (item_discount, order_discount)
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('max_discount', 15, 2)->nullable();
            $table->decimal('min_subtotal', 15, 2)->nullable();
            $table->unsignedSmallInteger('buy_qty')->nullable();
            $table->unsignedSmallInteger('get_qty')->nullable();
            // all / products / categories
            $table->string('applies_to', 12)->default('all');
            $table->json('product_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->json('outlet_ids')->nullable();
            // 1 = Senin … 7 = Minggu (ISO); null = setiap hari
            $table->json('days')->nullable();
            $table->string('time_start', 5)->nullable();
            $table->string('time_end', 5)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('promo_discount', 15, 2)->default(0)->after('stamp_free_total');
            // [{promotion_id, name, amount}]
            $table->json('promotions')->nullable()->after('promo_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['promo_discount', 'promotions']));
        Schema::dropIfExists('promotions');
    }
};
