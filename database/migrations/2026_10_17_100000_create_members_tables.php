<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Program member M1 (ADR 0013, SPEC Q60–Q61): member milik tenant, setelan program per tenant, outlet ikut/tidak,
 * member di order (snapshot nama). Kolom poin/tier/login disiapkan untuk M2–M5 agar tidak perlu migrasi ulang.
 * Hapus member = anonimkan (anonymized_at, data pribadi dikosongkan) — baris tetap untuk transaksi & ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 12);
            $table->string('name', 100);
            // +62…; null hanya setelah dianonimkan
            $table->string('phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedTinyInteger('birth_day')->nullable();
            $table->unsignedTinyInteger('birth_month')->nullable();
            $table->string('notes', 255)->nullable();
            // Halaman member (M4) & bukti tukar poin (M3)
            $table->string('password')->nullable();
            $table->boolean('must_change_password')->default(false);
            $table->string('pin')->nullable();
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
            // Poin, tier & statistik (M2, M5)
            $table->uuid('tier_id')->nullable();
            $table->integer('points_balance')->default(0);
            $table->decimal('spend_12m', 15, 2)->default(0);
            $table->decimal('total_spent', 15, 2)->default(0);
            $table->unsignedInteger('visit_count')->default(0);
            $table->timestamp('last_visit_at')->nullable();
            $table->foreignUuid('joined_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->timestamp('consent_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('anonymized_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('outlet_member_program', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique('outlet_id');
            $table->index('tenant_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignUuid('member_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // Snapshot nama saat transaksi (tetap terbaca walau member dianonimkan)
            $table->string('member_name', 100)->nullable()->after('member_id');
            $table->index(['tenant_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'member_id']);
            $table->dropConstrainedForeignId('member_id');
            $table->dropColumn('member_name');
        });
        Schema::dropIfExists('outlet_member_program');
        Schema::dropIfExists('members');
        Schema::dropIfExists('member_settings');
    }
};
