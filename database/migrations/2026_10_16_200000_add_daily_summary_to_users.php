<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ringkasan harian lewat email (Q57, R5): opt-in per user (bawaan aktif untuk owner) dan tanggal lokal
 * terakhir yang sudah dikirim (satu email per hari walau command berjalan tiap jam).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('daily_summary_email')->default(false)->after('web_cashier_access');
            $table->date('last_daily_summary_on')->nullable()->after('daily_summary_email');
        });

        DB::table('users')->where('role', 'owner')->update(['daily_summary_email' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['daily_summary_email', 'last_daily_summary_on']);
        });
    }
};
