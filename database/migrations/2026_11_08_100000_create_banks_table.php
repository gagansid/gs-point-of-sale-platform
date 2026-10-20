<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Master bank (Q124, Sistem → Bank di /admin): nama, singkatan & warna merek untuk logo vektor bawaan, logo unggahan
 * opsional (PNG/JPG/WEBP). Dipakai rekening transfer manual tagihan langganan. Bank umum Indonesia diisi di sini agar
 * instalasi baru langsung punya daftar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 80);
            $table->string('short_name', 12);
            $table->string('color', 7)->default('#2d4282');
            $table->string('text_color', 7)->default('#ffffff');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        $now = now();
        $banks = [
            ['bca', 'Bank Central Asia', 'BCA', '#0060af', '#ffffff'],
            ['mandiri', 'Bank Mandiri', 'mandiri', '#003d79', '#ffb700'],
            ['bni', 'Bank Negara Indonesia', 'BNI', '#f15a23', '#ffffff'],
            ['bri', 'Bank Rakyat Indonesia', 'BRI', '#00529c', '#ffffff'],
            ['bsi', 'Bank Syariah Indonesia', 'BSI', '#00a39d', '#ffffff'],
            ['cimb', 'CIMB Niaga', 'CIMB', '#7b0016', '#ffffff'],
            ['permata', 'Bank Permata', 'Permata', '#009a44', '#ffffff'],
            ['danamon', 'Bank Danamon', 'Danamon', '#f28b00', '#ffffff'],
            ['btn', 'Bank Tabungan Negara', 'BTN', '#1c4e9d', '#ffffff'],
            ['jago', 'Bank Jago', 'Jago', '#f9b21d', '#1e2633'],
            ['seabank', 'SeaBank Indonesia', 'SeaBank', '#ff6c00', '#ffffff'],
            ['ocbc', 'OCBC Indonesia', 'OCBC', '#e30613', '#ffffff'],
        ];
        DB::table('banks')->insert(array_map(fn (array $b, int $i): array => [
            'id' => (string) Str::uuid(), 'code' => $b[0], 'name' => $b[1], 'short_name' => $b[2], 'color' => $b[3], 'text_color' => $b[4],
            'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
        ], $banks, array_keys($banks)));
    }

    public function down(): void
    {
        Schema::dropIfExists('banks');
    }
};
