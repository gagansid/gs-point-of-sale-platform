<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot harga modal per item (R1, Q53) agar HPP & laba kotor tidak berubah saat cost_price diubah.
 * Null = harga modal belum diisi. Transaksi lama diisi dari harga modal produk saat ini (perkiraan terbaik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('unit_price');
        });

        // Backfill per produk ber-harga modal (portabel: MySQL, MariaDB, SQLite)
        DB::table('products')->whereNotNull('cost_price')->select(['id', 'cost_price'])->orderBy('id')
            ->each(function (object $product): void {
                DB::table('order_items')->where('product_id', $product->id)->update(['unit_cost' => $product->cost_price]);
            });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_cost');
        });
    }
};
