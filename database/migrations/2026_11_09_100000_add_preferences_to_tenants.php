<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferensi bisnis tingkat owner (Q128): tampilan dashboard (warna utama, mode terang/gelap) dan status panduan
 * "Mulai berjualan" yang disembunyikan. JSON agar setelan kecil bertambah tanpa migrasi baru; dibaca lewat
 * App\Support\TenantPreferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->json('preferences')->nullable()->after('limit_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('preferences');
        });
    }
};
