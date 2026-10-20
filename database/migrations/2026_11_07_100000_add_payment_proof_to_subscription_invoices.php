<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pembayaran tagihan langganan 2026-10-20 (Q123): owner memilih metode (QRIS / VA Midtrans, atau transfer manual) dan
 * untuk transfer manual mengunggah bukti; admin memverifikasi (lunas) atau menolak bukti dengan alasan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('paid_via');
            $table->string('proof_path')->nullable()->after('snap_url');
            $table->string('proof_sender', 120)->nullable()->after('proof_path');
            $table->string('proof_note', 500)->nullable()->after('proof_sender');
            $table->timestamp('proof_submitted_at')->nullable()->after('proof_note');
            $table->uuid('proof_submitted_by')->nullable()->after('proof_submitted_at');
            $table->string('proof_rejected_reason', 500)->nullable()->after('proof_submitted_by');
            $table->timestamp('proof_rejected_at')->nullable()->after('proof_rejected_reason');
            $table->index(['status', 'proof_submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropIndex(['status', 'proof_submitted_at']);
            $table->dropColumn(['payment_method', 'proof_path', 'proof_sender', 'proof_note', 'proof_submitted_at', 'proof_submitted_by', 'proof_rejected_reason', 'proof_rejected_at']);
        });
    }
};
