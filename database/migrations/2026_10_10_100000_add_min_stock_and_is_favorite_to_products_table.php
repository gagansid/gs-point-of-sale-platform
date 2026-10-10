<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batas stok minimum per produk ("stok menipis") dan tanda favorit outlet (tampil di kasir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Null = tanpa peringatan. Hanya berlaku untuk produk yang dilacak stoknya
            $table->unsignedInteger('min_stock')->nullable()->after('stock_qty');
            $table->boolean('is_favorite')->default(false)->after('is_available');

            $table->index(['tenant_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'is_favorite']);
            $table->dropColumn(['min_stock', 'is_favorite']);
        });
    }
};
