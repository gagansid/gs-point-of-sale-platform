<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perubahan stok (penyesuaian manual, penjualan, void). Tidak pernah diubah/dihapus.
 * id penyesuaian manual dikirim client sebagai idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->integer('qty_change');
            $table->integer('qty_after');
            $table->string('reason')->nullable();
            // Order asal (penjualan/void), diisi modul order
            $table->uuid('reference_id')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
