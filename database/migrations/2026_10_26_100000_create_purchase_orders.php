<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase order ke supplier (ADR 0021, Q100–Q101); stok masuk bisa berasal dari PO. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $table->date('ordered_on');
            $table->date('expected_on')->nullable();
            $table->string('notes', 255)->nullable();
            // draft / ordered / partial / received / cancelled
            $table->string('status', 12)->default('draft');
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'number']);
            $table->index(['tenant_id', 'status', 'ordered_on']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->unsignedInteger('qty');
            $table->unsignedInteger('received_qty')->default(0);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('stock_receipts', function (Blueprint $table): void {
            $table->foreignUuid('purchase_order_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
