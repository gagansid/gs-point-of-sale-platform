<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur sebagian per item (ADR 0018, Q92–Q94): dokumen retur + barisnya, jumlah yang sudah diretur di order & item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->decimal('refunded_total', 15, 2)->default(0)->after('paid_total');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->unsignedInteger('refunded_qty')->default(0)->after('qty');
        });

        Schema::create('order_refunds', function (Blueprint $table): void {
            // ID dari klien = idempotency key
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            // Shift & perangkat tempat uang dikeluarkan (bisa berbeda dari shift order)
            $table->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255);
            // cash = kas keluar laci, manual = diproses di luar sistem
            $table->string('method', 10);
            $table->boolean('restock')->default(true);
            // Nilai retur (bagian grand total) = payout_amount + nilai bagian poin
            $table->decimal('amount', 15, 2);
            $table->decimal('payout_amount', 15, 2);
            $table->unsignedInteger('points_returned')->default(0);
            $table->unsignedInteger('points_reversed')->default(0);
            $table->foreignUuid('cash_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'created_at']);
            $table->index(['order_id']);
        });

        Schema::create('order_refund_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_refund_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('order_item_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->unsignedInteger('qty');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refund_items');
        Schema::dropIfExists('order_refunds');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('refunded_qty'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('refunded_total'));
    }
};
