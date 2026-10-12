<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stok I3 (ADR 0015, Q82): transfer antar outlet dua langkah — kirim (stok asal berkurang, dalam perjalanan) → terima
 * (stok tujuan bertambah sesuai qty diterima; selisih tercatat). Batal sebelum diterima mengembalikan stok asal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            // Nomor dari outlet asal: {outlet}-TR-{YYMMDD}-{urut}
            $table->string('number', 40);
            $table->foreignUuid('from_outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->foreignUuid('to_outlet_id')->constrained('outlets')->restrictOnDelete();
            // draft / in_transit / received / cancelled
            $table->string('status', 12)->default('draft');
            $table->string('notes', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignUuid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['from_outlet_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index('to_outlet_id');
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 150);
            $table->unsignedInteger('qty_sent');
            // null = belum diterima; kurang dari dikirim = selisih (hilang/rusak di jalan)
            $table->unsignedInteger('qty_received')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
