<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengecualian hak akses per karyawan (ADR 0026, opsi B): izin tambahan & izin dicabut, relatif terhadap hak akses
 * role-nya di bisnis itu. NULL = mengikuti role. Diatur owner lewat SetEmployeePermissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('permissions_granted')->nullable()->after('role');
            $table->json('permissions_revoked')->nullable()->after('permissions_granted');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['permissions_granted', 'permissions_revoked']);
        });
    }
};
