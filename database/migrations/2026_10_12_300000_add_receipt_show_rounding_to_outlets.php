<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC Q48: tampilkan baris pembulatan di struk? Bawaan tidak (pembulatan digabung ke total).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->boolean('receipt_show_rounding')->default(false)->after('receipt_footer');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('receipt_show_rounding');
        });
    }
};
