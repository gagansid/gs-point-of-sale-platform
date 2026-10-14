<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bahan baku, resep, opsi berbahan, dan paket (ADR 0020, Q97–Q99).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // regular / ingredient / bundle
            $table->string('kind', 12)->default('regular')->after('category_id')->index();
            $table->string('unit', 20)->default('pcs')->after('kind');
        });

        Schema::create('product_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('products')->restrictOnDelete();
            // Dalam satuan komponen (stok bilangan bulat)
            $table->unsignedInteger('qty');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'component_id']);
            $table->index('component_id');
        });

        Schema::create('option_components', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('option_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            $table->unique(['option_id', 'component_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_components');
        Schema::dropIfExists('product_components');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'unit']);
        });
    }
};
