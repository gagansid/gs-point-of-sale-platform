<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            // Null = tanpa kategori (kategori dihapus tidak menghapus produknya)
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            // SKU & barcode unik per tenant di validasi (bukan index unik) agar data yang
            // dihapus lunak tidak menghalangi pemakaian ulang kode
            $table->string('sku', 50)->nullable();
            $table->string('barcode', 50)->nullable();
            $table->decimal('price', 15, 2);
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->boolean('track_stock')->default(false);
            // Boleh minus: penjualan tidak ditolak karena stok (SPEC Aturan bisnis → Stok)
            $table->integer('stock_qty')->default(0);
            $table->string('image_path')->nullable();
            // is_active: tampil di katalog. is_available: tanda "habis" harian tanpa mengubah data produk
            $table->boolean('is_active')->default(true);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'barcode']);
            $table->index(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
