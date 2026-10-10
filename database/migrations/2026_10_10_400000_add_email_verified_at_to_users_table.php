<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi email owner (ADR 0009, SPEC Q31). Akun yang sudah ada dibuat oleh tim (admin/seeder)
 * dan dianggap terverifikasi; hanya pendaftar mandiri di gspos.id/daftar yang mulai belum terverifikasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });

        DB::table('users')->whereNotNull('email')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
