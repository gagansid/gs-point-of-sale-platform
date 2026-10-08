<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // restrict: data bisnis tidak boleh ikut terhapus karena tenant dihapus
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 10);
            $table->string('name');
            $table->text('address')->nullable();
            // Zona tampilan & tanggal nomor order (ADR 0001)
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('service_charge_rate', 5, 2)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            // Pembulatan grand total ke kelipatan Rp N (0 = tanpa pembulatan)
            $table->unsignedInteger('rounding')->default(0);
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            // Batas diskon manual per role dalam persen, mis. {"cashier": 10, "supervisor": 25}
            $table->json('discount_limits')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Kode outlet menjadi awalan nomor order, unik dalam satu tenant
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlets');
    }
};
