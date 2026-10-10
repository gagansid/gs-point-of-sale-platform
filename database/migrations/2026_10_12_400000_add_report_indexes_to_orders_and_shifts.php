<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index untuk beranda dashboard & API /reports: setiap query laporan memfilter
 * outlet + status + rentang waktu (completed_at untuk omzet, voided_at untuk void,
 * closed_at untuk audit selisih kas shift).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['outlet_id', 'status', 'completed_at'], 'orders_report_completed_index');
            $table->index(['outlet_id', 'status', 'voided_at'], 'orders_report_voided_index');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->index(['outlet_id', 'closed_at'], 'shifts_report_closed_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_report_completed_index');
            $table->dropIndex('orders_report_voided_index');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropIndex('shifts_report_closed_index');
        });
    }
};
