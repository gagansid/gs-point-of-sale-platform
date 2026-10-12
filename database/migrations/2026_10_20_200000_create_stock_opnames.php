<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok I2 (ADR 0015, Q81): stok opname — lembar hitung fisik per outlet; saat diposting selisih (fisik − stok saat
 * posting) menjadi riwayat `opname` dan nilai selisih dihitung dari harga modal saat itu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            // Lingkup hitung: null = semua produk lacak stok outlet
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->date('counted_on');
            $table->string('notes', 255)->nullable();
            // draft / posted
            $table->string('status', 12)->default('draft');
            $table->integer('difference_qty')->default(0);
            $table->decimal('difference_value', 15, 2)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['tenant_id', 'status', 'counted_on']);
        });

        Schema::create('stock_opname_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            // Stok sistem saat lembar dibuat (patokan penghitung)
            $table->integer('system_qty');
            // null = tidak dihitung (dilewati saat posting)
            $table->integer('counted_qty')->nullable();
            $table->integer('stock_at_post')->nullable();
            $table->integer('difference')->nullable();
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('difference_value', 15, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['stock_opname_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
    }
};
