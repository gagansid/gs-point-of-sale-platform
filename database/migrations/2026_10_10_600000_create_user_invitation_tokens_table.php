<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token undangan "atur kata sandi" owner (ADR 0009, SPEC Q34). Terpisah dari password_reset_tokens
 * agar masa berlaku 3 hari undangan tidak ikut berlaku untuk link lupa kata sandi (60 menit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitation_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitation_tokens');
    }
};
