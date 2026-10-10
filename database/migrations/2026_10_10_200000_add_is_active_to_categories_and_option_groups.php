<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status aktif kategori & grup opsi (SPEC Q27): yang nonaktif tidak dikirim ke aplikasi kasir.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['categories', 'option_groups'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->boolean('is_active')->default(true)->after('sort_order');
            });
        }
    }

    public function down(): void
    {
        foreach (['categories', 'option_groups'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('is_active');
            });
        }
    }
};
