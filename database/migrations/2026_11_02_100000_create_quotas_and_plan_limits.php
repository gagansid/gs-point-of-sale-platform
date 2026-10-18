<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Kuota paket (ADR 0025 B1): master data `quotas` (dikelola langsung di database, tanpa CRUD), batas per paket
 * `plans.limits` dan batas khusus tenant `tenants.limit_overrides` (JSON {key: int}). Menggantikan max_outlets.
 *
 * Data lama: plans.max_outlets → limits.outlets. tenants.max_outlets dulu disalin dari paket saat paket diterapkan;
 * nilai yang sama dengan paketnya tidak perlu disimpan lagi, selain itu (tanpa paket / diatur admin) jadi override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['outlets', 'Outlet aktif', 'Outlet yang aktif', 10],
            ['users', 'Karyawan aktif', 'Akun karyawan aktif, termasuk owner', 20],
            ['devices', 'Perangkat kasir', 'Perangkat kasir yang tidak dicabut (aplikasi Android & kasir web)', 30],
            ['products', 'Produk aktif', 'Produk & paket aktif yang belum dihapus (bahan baku tidak dihitung)', 40],
            ['members', 'Member aktif', 'Member terdaftar yang aktif', 50],
        ];
        DB::table('quotas')->insert(array_map(fn (array $r): array => [
            'id' => (string) Str::uuid7(), 'key' => $r[0], 'name' => $r[1], 'description' => $r[2],
            'sort_order' => $r[3], 'created_at' => $now, 'updated_at' => $now,
        ], $rows));

        Schema::table('plans', fn (Blueprint $table) => $table->json('limits')->nullable()->after('price_yearly'));
        Schema::table('tenants', fn (Blueprint $table) => $table->json('limit_overrides')->nullable()->after('subscription_ends_at'));

        $planOutlets = DB::table('plans')->pluck('max_outlets', 'id');
        foreach ($planOutlets as $id => $max) {
            if ($max !== null) {
                DB::table('plans')->where('id', $id)->update(['limits' => json_encode(['outlets' => (int) $max])]);
            }
        }

        DB::table('tenants')->whereNotNull('max_outlets')->orderBy('id')->each(function (object $tenant) use ($planOutlets): void {
            $fromPlan = $tenant->plan_id !== null ? ($planOutlets[$tenant->plan_id] ?? null) : null;
            if ($tenant->plan_id === null || (int) $tenant->max_outlets !== (int) $fromPlan) {
                DB::table('tenants')->where('id', $tenant->id)->update(['limit_overrides' => json_encode(['outlets' => (int) $tenant->max_outlets])]);
            }
        });

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('max_outlets'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('max_outlets'));
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $table) => $table->unsignedSmallInteger('max_outlets')->nullable());
        Schema::table('tenants', fn (Blueprint $table) => $table->unsignedSmallInteger('max_outlets')->nullable());

        $planOutlets = [];
        foreach (DB::table('plans')->get(['id', 'limits']) as $plan) {
            $planOutlets[$plan->id] = json_decode((string) $plan->limits, true)['outlets'] ?? null;
            DB::table('plans')->where('id', $plan->id)->update(['max_outlets' => $planOutlets[$plan->id]]);
        }
        foreach (DB::table('tenants')->get(['id', 'plan_id', 'limit_overrides']) as $tenant) {
            $max = json_decode((string) $tenant->limit_overrides, true)['outlets'] ?? ($tenant->plan_id !== null ? ($planOutlets[$tenant->plan_id] ?? null) : null);
            DB::table('tenants')->where('id', $tenant->id)->update(['max_outlets' => $max]);
        }

        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('limits'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('limit_overrides'));
        Schema::dropIfExists('quotas');
    }
};
