<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perangkat kasir terdaftar. Device punya token Sanctum sendiri (ADR 0002).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('device_uid', 100);
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // Diisi saat owner mencabut akses device hilang; token ikut dihapus
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'device_uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
