<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Akses kasir web per karyawan (Q54), diatur owner di menu Karyawan. Owner (user.manage) selalu boleh.
 * Kasir & supervisor yang sudah ada diaktifkan (mereka sudah punya username untuk kasir web, Q35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('web_cashier_access')->default(false)->after('is_active');
        });

        DB::table('users')->whereIn('role', ['cashier', 'supervisor'])->update(['web_cashier_access' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('web_cashier_access');
        });
    }
};
