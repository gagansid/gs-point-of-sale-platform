<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Username kasir/supervisor untuk kasir web (cadangan bila tablet rusak, SPEC Q35):
 * login = kode bisnis (slug tenant) + username + kata sandi. Unik per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 30)->nullable()->after('email');
            $table->unique(['tenant_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'username']);
            $table->dropColumn('username');
        });
    }
};
