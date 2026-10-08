<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versi aplikasi Flutter per platform; di bawah min_version → 426 APP_UPDATE_REQUIRED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('platform', 20)->unique();
            $table->string('min_version', 20);
            $table->string('latest_version', 20);
            $table->boolean('force_update')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
