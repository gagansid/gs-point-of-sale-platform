<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Master data fitur yang bisa dibatasi paket (ADR 0025). Dikelola langsung di database (tanpa CRUD); `key` dipakai di
 * kode (Features::enabled, middleware feature:{key}) dan di plans.features. Baris awal = 6 fitur ADR 0024.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['member', 'Program member', 'Poin, tier, kartu stempel, voucher'],
            ['self_order', 'Self-order QR meja', 'Pelanggan memesan dari QR meja'],
            ['stock', 'Stok lanjutan', 'Stok masuk, opname, transfer, supplier, purchase order'],
            ['recipes', 'Bahan baku & resep', 'Bahan baku, resep, dan paket produk'],
            ['promotions', 'Promo otomatis', 'Promo yang berlaku otomatis di kasir'],
            ['credit', 'Kasbon member', 'Transaksi bayar nanti untuk member'],
        ];

        DB::table('features')->insert(array_map(fn (array $r, int $i): array => [
            'id' => (string) Str::uuid7(), 'key' => $r[0], 'name' => $r[1], 'description' => $r[2],
            'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
        ], $rows, array_keys($rows)));
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
