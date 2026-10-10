<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu & metode bayar per outlet (ADR 0011, SPEC Q42–Q45):
 * - outlet_product.is_listed: produk dijual di outlet;
 * - outlet_option: opsi habis per outlet;
 * - outlet_payment_method: metode bayar aktif per outlet.
 * Tanpa baris = dijual / tersedia / aktif, jadi data lama tidak perlu disalin (perilaku tidak berubah).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_product', function (Blueprint $table): void {
            $table->boolean('is_listed')->default(true)->after('product_id');
        });

        Schema::create('outlet_option', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('option_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'option_id']);
            $table->index(['tenant_id', 'option_id']);
        });

        Schema::create('outlet_payment_method', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_method_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'payment_method_id']);
            $table->index(['tenant_id', 'payment_method_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_payment_method');
        Schema::dropIfExists('outlet_option');

        Schema::table('outlet_product', function (Blueprint $table): void {
            $table->dropColumn('is_listed');
        });
    }
};
