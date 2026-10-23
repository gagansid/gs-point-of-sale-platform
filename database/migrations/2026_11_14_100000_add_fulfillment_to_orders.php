<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pengantaran pesanan meja (ADR 0027 §4, Q137): Antre → Disiapkan → Siap diantar → Diantar / Selesai otomatis.
 * Kolom nullable: order non-meja tetap NULL. Waktu lunas = completed_at (sudah ada). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // queued / preparing / ready / served / auto_completed
            $table->string('fulfillment_status', 20)->nullable()->after('refund_required');
            $table->timestamp('prepared_at')->nullable()->after('fulfillment_status');
            $table->timestamp('ready_at')->nullable()->after('prepared_at');
            $table->timestamp('served_at')->nullable()->after('ready_at');
            $table->foreignUuid('served_by')->nullable()->after('served_at')->constrained('users')->nullOnDelete();
            $table->boolean('fulfillment_auto')->default(false)->after('served_by');
            // Urungkan 5 detik: tahap sebelumnya + pelaku & waktu perubahan terakhir
            $table->string('fulfillment_previous', 20)->nullable()->after('fulfillment_auto');
            $table->uuid('fulfillment_updated_by')->nullable()->after('fulfillment_previous');
            $table->timestamp('fulfillment_updated_at')->nullable()->after('fulfillment_updated_by');

            $table->index(['outlet_id', 'fulfillment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['outlet_id', 'fulfillment_status']);
            $table->dropConstrainedForeignId('served_by');
            $table->dropColumn(['fulfillment_status', 'prepared_at', 'ready_at', 'served_at', 'fulfillment_auto', 'fulfillment_previous', 'fulfillment_updated_by', 'fulfillment_updated_at']);
        });
    }
};
