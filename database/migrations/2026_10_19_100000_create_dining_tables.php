<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-order S1 (ADR 0014, Q68–Q69): meja & QR per outlet, setelan self-order per outlet, kanal order, dan status
 * order baru (awaiting_payment, expired). Nama tabel `dining_tables` (bukan `tables`: kata kunci MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained()->restrictOnDelete();
            $table->string('name', 50);
            $table->string('area', 50)->nullable();
            // Token acak di URL QR; diganti = QR lama langsung tidak berlaku
            $table->string('qr_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
            $table->index('tenant_id');
        });

        Schema::create('self_order_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('outlet_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('payment_timeout_minutes')->default(15);
            // Jam layanan (waktu lokal outlet); null = sepanjang hari
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('allow_pay_at_cashier')->default(true);
            $table->string('welcome_text', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // pos (kasir) / self_order (QR meja)
            $table->string('channel', 20)->default('pos')->after('order_type');
            $table->foreignUuid('dining_table_id')->nullable()->after('table_label')->constrained()->nullOnDelete();
            // Self-order: diterima kasir (mulai hitung batas waktu bayar)
            $table->timestamp('accepted_at')->nullable()->after('completed_at');
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['outlet_id', 'status']);
            $table->dropConstrainedForeignId('dining_table_id');
            $table->dropColumn(['channel', 'accepted_at']);
        });
        Schema::dropIfExists('self_order_settings');
        Schema::dropIfExists('dining_tables');
    }
};
