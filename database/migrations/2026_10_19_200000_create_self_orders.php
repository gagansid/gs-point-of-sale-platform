<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-order S2 (ADR 0014, Q68–Q71): pesanan dari HP sebelum diterima kasir. Order (tabel orders) baru dibuat saat
 * kasir menerima — order wajib punya shift & kasir — dengan status awaiting_payment; pesanan ditolak/kedaluwarsa
 * sebelum diterima tidak pernah menjadi order. `id` dari HP = idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('dining_table_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 100);
            // [{product_id, qty, option_ids, notes}] — harga dihitung ulang saat diterima
            $table->json('items');
            $table->string('notes', 255)->nullable();
            $table->string('voucher_code', 30)->nullable();
            // Perkiraan total saat dikirim (tampilan HP & kasir); tagihan final dari order
            $table->decimal('estimated_total', 15, 2)->default(0);
            // pending / accepted / rejected / expired
            $table->string('status', 20)->default('pending');
            $table->string('reject_reason', 255)->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status', 'created_at']);
            $table->index(['dining_table_id', 'status']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_orders');
    }
};
