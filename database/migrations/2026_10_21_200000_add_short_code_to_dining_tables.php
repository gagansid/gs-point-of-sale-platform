<?php

declare(strict_types=1);

use App\Models\DiningTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * URL pendek QR meja (gspos.id/t/{kode}): kode 6 karakter acak tanpa huruf/angka mirip, unik global, dialihkan ke URL
 * asli /order/t/{qr_token}. Meja lama diberi kode saat migrasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table): void {
            $table->string('short_code', 8)->nullable()->unique()->after('qr_token');
        });

        foreach (DB::table('dining_tables')->whereNull('short_code')->pluck('id') as $id) {
            DB::table('dining_tables')->where('id', $id)->update(['short_code' => DiningTable::newShortCode()]);
        }
    }

    public function down(): void
    {
        // SQLite menolak menghapus kolom yang masih punya index
        Schema::table('dining_tables', function (Blueprint $table): void {
            $table->dropUnique(['short_code']);
            $table->dropColumn('short_code');
        });
    }
};
