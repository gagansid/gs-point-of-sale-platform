<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi bawaan per grup (Q129): terpilih otomatis saat produk dibuka di kasir/self-order/aplikasi, owner yang
 * menentukan. Isi awal menyamakan perilaku lama (sebelumnya ditebak otomatis): grup pilih-satu wajib → opsi pertama,
 * grup pilih-satu opsional → opsi gratis pertama (mis. Gula "Normal"). Grup pilih-banyak tanpa bawaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('options', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('price_delta');
        });

        DB::table('option_groups')->where('max_select', 1)->whereNull('deleted_at')->orderBy('id')
            ->each(function (object $group): void {
                $option = DB::table('options')->where('option_group_id', $group->id)->whereNull('deleted_at')
                    ->when((int) $group->min_select === 0, fn ($q) => $q->where('price_delta', '<=', 0))
                    ->orderBy('sort_order')->orderBy('id')->first(['id']);
                if ($option !== null) {
                    DB::table('options')->where('id', $option->id)->update(['is_default' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('options', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
