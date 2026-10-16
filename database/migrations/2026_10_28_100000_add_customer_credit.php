<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kasbon / piutang member (ADR 0023, Q105–Q107). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_settings', function (Blueprint $table): void {
            $table->boolean('credit_enabled')->default(false);
            $table->decimal('default_credit_limit', 15, 2)->nullable();
        });

        Schema::table('members', function (Blueprint $table): void {
            // null = batas bawaan program
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('receivable_balance', 15, 2)->default(0);
        });

        Schema::create('receivable_entries', function (Blueprint $table): void {
            // Pelunasan: ID dari klien = idempotency key
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            // charge / payment / void / refund
            $table->string('type', 10);
            // + menambah saldo (charge), − mengurangi (payment, void, refund)
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->foreignUuid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_entries');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['credit_limit', 'receivable_balance']));
        Schema::table('member_settings', fn (Blueprint $table) => $table->dropColumn(['credit_enabled', 'default_credit_limit']));
    }
};
