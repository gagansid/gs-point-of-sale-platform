<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Paket langganan & tagihan SaaS (ADR 0024, Q108–Q111). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->decimal('price_monthly', 15, 2);
            $table->decimal('price_yearly', 15, 2);
            $table->unsignedSmallInteger('max_outlets')->nullable();
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignUuid('plan_id')->nullable()->after('max_outlets')->constrained()->nullOnDelete();
            // monthly / yearly
            $table->string('billing_cycle', 10)->nullable()->after('plan_id');
        });

        Schema::create('subscription_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->unique();
            $table->foreignUuid('plan_id')->constrained()->restrictOnDelete();
            $table->string('plan_name', 60);
            $table->string('billing_cycle', 10);
            $table->decimal('amount', 15, 2);
            // pending / paid / cancelled / expired
            $table->string('status', 10)->default('pending');
            $table->timestamp('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            // midtrans / manual
            $table->string('paid_via', 10)->nullable();
            $table->string('gateway_order_id', 60)->nullable()->unique();
            $table->string('snap_url', 255)->nullable();
            $table->foreignUuid('paid_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropConstrainedForeignId('plan_id'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('billing_cycle'));
        Schema::dropIfExists('plans');
    }
};
