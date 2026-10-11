<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-order S3 (ADR 0014, Q72–Q73): akun Midtrans milik owner (key terenkripsi), transaksi Snap per pembayaran
 * online, dan tanda "perlu refund manual" untuk order online lunas yang di-void.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 20)->default('midtrans');
            $table->boolean('enabled')->default(false);
            $table->boolean('is_production')->default(false);
            // Cast `encrypted`: tidak pernah ditampilkan lagi setelah disimpan
            $table->text('server_key')->nullable();
            $table->text('client_key')->nullable();
            // Metode Snap yang diizinkan owner, mis. ["qris","gopay","shopeepay","bank_transfer"]
            $table->json('enabled_payments')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('gateway_transactions', function (Blueprint $table): void {
            // = order_id di Midtrans
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            // pending / settlement / expire / cancel / deny / failure / refund_required
            $table->string('status', 20)->default('pending');
            $table->string('snap_token', 100)->nullable();
            $table->string('redirect_url', 255)->nullable();
            $table->string('transaction_id', 100)->nullable();
            $table->string('payment_type', 40)->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('tenant_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Order dibayar online lalu di-void: dana harus dikembalikan manual oleh owner (Q73)
            $table->boolean('refund_required')->default(false)->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('refund_required'));
        Schema::dropIfExists('gateway_transactions');
        Schema::dropIfExists('payment_gateway_settings');
    }
};
