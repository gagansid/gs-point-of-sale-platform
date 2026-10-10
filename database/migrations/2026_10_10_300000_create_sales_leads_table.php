<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calon pelanggan dari form "Hubungi sales" di halaman depan gspos.id (ADR 0008).
 * Data platform (bukan milik tenant): hanya dilihat di panel admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('business_name', 150);
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('business_type', 30)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            $table->text('notes')->nullable();
            // Hash IP (bukan IP mentah) untuk melacak spam tanpa menyimpan data pribadi berlebih
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
