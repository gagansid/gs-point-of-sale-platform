<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ganti paket di tengah masa aktif (ADR 0024 revisi): tagihan upgrade prorata, dan paket berikutnya yang dijadwalkan
 * berlaku di akhir masa aktif (downgrade / ganti siklus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->string('kind', 10)->default('renewal')->after('number');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignUuid('next_plan_id')->nullable()->after('billing_cycle')->constrained('plans')->nullOnDelete();
            $table->string('next_billing_cycle', 10)->nullable()->after('next_plan_id');
            $table->timestamp('plan_changes_at')->nullable()->after('next_billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('next_plan_id');
            $table->dropColumn(['next_billing_cycle', 'plan_changes_at']);
        });

        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
