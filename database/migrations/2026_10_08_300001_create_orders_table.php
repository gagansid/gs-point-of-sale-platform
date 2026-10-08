<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order, item, opsi item, pembayaran, dan penomoran harian. ID order & payment dibuat aplikasi
 * (idempotency key). Item & opsi menyimpan snapshot nama dan harga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sequences', function (Blueprint $table): void {
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            // Tanggal lokal outlet (ADR 0001)
            $table->date('date');
            $table->unsignedInteger('last_number')->default(0);

            $table->primary(['outlet_id', 'date']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 30);
            $table->string('order_type', 20);
            $table->string('table_label', 50)->nullable();
            $table->string('status', 20);
            $table->string('notes')->nullable();
            // Input diskon order (untuk hitung ulang open bill) dan hasil hitungnya
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->nullable();
            $table->decimal('order_discount', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);
            // Diskon item + diskon order
            $table->decimal('discount_total', 15, 2);
            $table->decimal('service_total', 15, 2);
            $table->decimal('tax_total', 15, 2);
            $table->decimal('rounding', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            // Setelan outlet saat order dihitung (struk & audit tetap konsisten bila setelan berubah)
            $table->decimal('service_rate', 5, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->boolean('tax_inclusive');
            $table->string('void_reason')->nullable();
            $table->foreignUuid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'order_number']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('options_total', 15, 2);
            $table->unsignedInteger('qty');
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->string('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('order_item_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('option_id')->constrained()->restrictOnDelete();
            $table->string('option_name', 100);
            $table->decimal('price_delta', 15, 2);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('category', 20);
            // amount = nominal yang dipakai membayar tagihan; tendered = uang diterima; change = kembalian
            $table->decimal('amount', 15, 2);
            $table->decimal('tendered', 15, 2);
            $table->decimal('change', 15, 2)->default(0);
            $table->string('reference', 50)->nullable();
            $table->string('status', 20);
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
    }
};
