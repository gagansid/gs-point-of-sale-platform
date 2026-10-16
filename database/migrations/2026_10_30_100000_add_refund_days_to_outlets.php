<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Batas hari retur per outlet (ADR 0018 revisi 2026-10-16): 0 = retur tidak diizinkan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->unsignedSmallInteger('refund_max_days')->default(30)->after('cash_out_approval_limit');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('refund_max_days'));
    }
};
