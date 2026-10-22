<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hak akses per bisnis (ADR 0026): owner mengatur permission role manager, supervisor, dan kasir. Satu baris per
 * role; tanpa baris = bawaan UserRole::permissions(). Owner selalu semua permission (tidak disimpan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            // Daftar permission yang diberikan (bukan pola wildcard)
            $table->json('permissions');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
