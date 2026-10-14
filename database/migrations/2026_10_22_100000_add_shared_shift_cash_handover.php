<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift bersama per perangkat (ADR 0017): setoran & sisa laci saat tutup, carry-over kas awal + koreksinya,
 * log operator (ganti kasir tanpa hitung), kas masuk/keluar, dan setelan laci per outlet.
 * Shift lama: closing_float null → shift berikutnya di perangkat itu tetap mengetik kas awal manual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            // Modal laci tetap (opsional): isian Disetor terisi otomatis = kas dihitung − modal
            $table->decimal('cash_float', 15, 2)->nullable()->after('monthly_sales_target');
            // Kunci layar kasir otomatis setelah X menit tidak aktif; null = mati
            $table->unsignedSmallInteger('auto_lock_minutes')->nullable()->default(5)->after('cash_float');
            // Kas keluar di atas nominal ini butuh PIN approval (kasir); null = tanpa approval
            $table->decimal('cash_out_approval_limit', 15, 2)->nullable()->default(100000)->after('auto_lock_minutes');
        });

        Schema::table('shifts', function (Blueprint $table): void {
            // manual / carry_over / adjusted
            $table->string('opening_cash_source', 12)->default('manual')->after('opening_cash');
            // Nilai carry-over sebelum dikoreksi (null = kas awal manual)
            $table->decimal('carried_cash', 15, 2)->nullable()->after('opening_cash_source');
            $table->foreignUuid('previous_shift_id')->nullable()->after('carried_cash')->constrained('shifts')->nullOnDelete();
            $table->string('opening_cash_note', 255)->nullable()->after('previous_shift_id');
            $table->foreignUuid('opening_cash_adjusted_by')->nullable()->after('opening_cash_note')->constrained('users')->nullOnDelete();
            $table->foreignUuid('opening_cash_approved_by')->nullable()->after('opening_cash_adjusted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('opening_cash_adjusted_at')->nullable()->after('opening_cash_approved_by');
            $table->decimal('cash_deposited', 15, 2)->nullable()->after('actual_cash');
            // = actual_cash − cash_deposited; menjadi kas awal shift berikutnya di perangkat yang sama
            $table->decimal('closing_float', 15, 2)->nullable()->after('cash_deposited');

            // Shift terakhir yang ditutup per perangkat (carry-over)
            $table->index(['device_id', 'closed_at']);
        });

        Schema::create('shift_operators', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'started_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            // ID dari klien = idempotency key
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            // in / out
            $table->string('type', 4);
            // CashMovementReason
            $table->string('reason', 20);
            $table->decimal('amount', 15, 2);
            $table->string('note', 255)->nullable();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'created_at']);
            $table->index(['tenant_id', 'outlet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('shift_operators');

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropIndex(['device_id', 'closed_at']);
            $table->dropConstrainedForeignId('previous_shift_id');
            $table->dropConstrainedForeignId('opening_cash_adjusted_by');
            $table->dropConstrainedForeignId('opening_cash_approved_by');
            $table->dropColumn(['opening_cash_source', 'carried_cash', 'opening_cash_note', 'opening_cash_adjusted_at', 'cash_deposited', 'closing_float']);
        });

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn(['cash_float', 'auto_lock_minutes', 'cash_out_approval_limit']);
        });
    }
};
