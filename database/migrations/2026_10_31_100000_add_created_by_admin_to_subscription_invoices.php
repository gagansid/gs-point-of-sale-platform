<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tagihan langganan yang dibuat super admin dari detail bisnis (ADR 0024, Q109): jejak pembuat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table) {
            $table->foreignUuid('created_by_admin_id')->nullable()->after('snap_url')->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by_admin_id'));
    }
};
