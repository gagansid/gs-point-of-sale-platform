<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add-on langganan (ADR 0025 C1): dijual di atas paket, dikelola admin (CRUD). Jenis `feature` memberi satu fitur
 * (key master data features); jenis `quota` menambah kuota (key master data quotas) sebanyak quota_amount per unit.
 * tenant_addons: add-on milik bisnis, satu masa aktif dengan paket (tanpa tanggal perpanjangan sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            // feature / quota
            $table->string('type', 10);
            $table->string('feature_key', 40)->nullable();
            $table->string('quota_key', 40)->nullable();
            $table->unsignedInteger('quota_amount')->nullable();
            $table->decimal('price_monthly', 15, 2);
            $table->decimal('price_yearly', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_addons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('addon_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            // active / cancelling (berhenti di akhir masa aktif, C2)
            $table->string('status', 12)->default('active');
            $table->timestamp('cancel_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'addon_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_addons');
        Schema::dropIfExists('addons');
    }
};
