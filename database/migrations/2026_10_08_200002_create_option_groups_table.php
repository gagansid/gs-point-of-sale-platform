<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grup opsi (Ukuran, Gula, Topping) + opsinya + relasi ke produk.
 * Ukuran minuman = grup dengan min_select 1 (tanpa tabel varian, SPEC Database).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('min_select')->default(0);
            $table->unsignedTinyInteger('max_select')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // tenant_id juga di sini agar setiap model bisnis terisolasi oleh BelongsToTenant
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('price_delta', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_option_groups', function (Blueprint $table): void {
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('option_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['product_id', 'option_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_option_groups');
        Schema::dropIfExists('options');
        Schema::dropIfExists('option_groups');
    }
};
