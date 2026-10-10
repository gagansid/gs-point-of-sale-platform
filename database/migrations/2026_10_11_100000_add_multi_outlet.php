<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-outlet (ADR 0010, SPEC Q36–Q41):
 * - outlets.is_active (outlet dinonaktifkan, tidak pernah dihapus), tenants.max_outlets (batas dari admin);
 * - outlet_user menggantikan users.outlet_id (satu karyawan boleh memegang beberapa outlet; owner = semua);
 * - outlet_product: stok, stok minimum, dan ketersediaan per outlet (harga & katalog tetap per bisnis);
 * - stock_movements.outlet_id.
 * Data lama dipindahkan: stok/ketersediaan produk disalin ke setiap outlet tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('discount_limits');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            // Null = tanpa batas (edisi self-hosted / belum diatur)
            $table->unsignedSmallInteger('max_outlets')->nullable();
        });

        Schema::create('outlet_user', function (Blueprint $table): void {
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['outlet_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('outlet_product', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            // Boleh minus: penjualan tidak ditolak karena stok (SPEC Aturan bisnis → Stok)
            $table->integer('stock_qty')->default(0);
            $table->unsignedInteger('min_stock')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignUuid('outlet_id')->nullable()->after('tenant_id')->constrained()->restrictOnDelete();
        });

        $this->moveData();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outlet_id');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['stock_qty', 'is_available', 'min_stock']);
        });
    }

    private function moveData(): void
    {
        $now = now();

        // Karyawan non-owner: outlet lama → outlet_user (owner otomatis memegang semua outlet)
        DB::table('users')->whereNotNull('outlet_id')->where('role', '!=', 'owner')
            ->orderBy('id')->each(function (object $user) use ($now): void {
                DB::table('outlet_user')->insert([
                    'outlet_id' => $user->outlet_id, 'user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
            });

        // Karyawan lama tanpa outlet (selain owner) → semua outlet tenant
        DB::table('users')->whereNull('outlet_id')->where('role', '!=', 'owner')
            ->orderBy('id')->each(function (object $user) use ($now): void {
                foreach (DB::table('outlets')->where('tenant_id', $user->tenant_id)->pluck('id') as $outletId) {
                    DB::table('outlet_user')->insert([
                        'outlet_id' => $outletId, 'user_id' => $user->id, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });

        DB::table('products')->orderBy('id')->each(function (object $product) use ($now): void {
            foreach (DB::table('outlets')->where('tenant_id', $product->tenant_id)->pluck('id') as $outletId) {
                DB::table('outlet_product')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $product->tenant_id,
                    'outlet_id' => $outletId,
                    'product_id' => $product->id,
                    'is_available' => $product->is_available,
                    'stock_qty' => $product->stock_qty,
                    'min_stock' => $product->min_stock,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        // Riwayat stok lama milik outlet pertama tenant
        foreach (DB::table('outlets')->orderBy('created_at')->get(['id', 'tenant_id'])->unique('tenant_id') as $outlet) {
            DB::table('stock_movements')->where('tenant_id', $outlet->tenant_id)->whereNull('outlet_id')
                ->update(['outlet_id' => $outlet->id]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->integer('stock_qty')->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedInteger('min_stock')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('outlet_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outlet_id');
        });

        Schema::dropIfExists('outlet_product');
        Schema::dropIfExists('outlet_user');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('max_outlets');
        });

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
