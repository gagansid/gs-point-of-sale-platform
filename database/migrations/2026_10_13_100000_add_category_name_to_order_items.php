<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot kategori di order_items (Q51) untuk laporan Kategori, seperti snapshot nama & harga.
 * Transaksi lama diisi dari kategori produk saat ini; produk tanpa kategori = "Tanpa kategori".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('category_name', 100)->default('Tanpa kategori')->after('product_name');
        });

        // Backfill per kategori (subquery portabel: MySQL, MariaDB, SQLite)
        DB::table('categories')->select(['id', 'name'])->orderBy('id')->each(function (object $category): void {
            DB::table('order_items')
                ->whereIn('product_id', DB::table('products')->select('id')->where('category_id', $category->id))
                ->update(['category_name' => $category->name]);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('category_name');
        });
    }
};
