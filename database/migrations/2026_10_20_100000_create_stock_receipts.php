<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok I1 (ADR 0015, Q79–Q80): supplier, dokumen stok masuk (draf → posting → batal) dengan harga beli & modal
 * rata-rata tertimbang, dan nomor dokumen stok per outlet per hari (dipakai juga opname & transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('document_sequences', function (Blueprint $table): void {
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            // SM = stok masuk, SO = opname, TR = transfer
            $table->string('type', 4);
            $table->date('date');
            $table->unsignedInteger('last_number')->default(0);

            $table->primary(['outlet_id', 'type', 'date']);
        });

        Schema::create('stock_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('supplier_id')->nullable()->constrained()->nullOnDelete();
            // Tanggal barang diterima (lokal outlet)
            $table->date('received_on');
            $table->string('invoice_number', 50)->nullable();
            $table->string('notes', 255)->nullable();
            // draft / posted / cancelled
            $table->string('status', 12)->default('draft');
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamp('posted_at')->nullable();
            $table->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['tenant_id', 'status', 'received_on']);
        });

        Schema::create('stock_receipt_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->unsignedInteger('qty');
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            // Modal rata-rata tertimbang sebelum & sesudah posting (Q80)
            $table->decimal('cost_before', 15, 2)->nullable();
            $table->decimal('cost_after', 15, 2)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('suppliers');
    }
};
