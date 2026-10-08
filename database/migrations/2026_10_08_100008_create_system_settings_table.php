<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setelan sistem global yang diubah super admin dari panel (mis. wajib 2FA).
 * Bukan data tenant. Akses lewat App\Support\SystemSettings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('key', 100)->primary();
            $table->json('value');
            // Admin terakhir yang mengubah (jejak audit sederhana)
            $table->foreignUuid('updated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
