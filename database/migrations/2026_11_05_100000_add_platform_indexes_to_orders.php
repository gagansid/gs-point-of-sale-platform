<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dasbor /admin (D3): agregat transaksi lintas tenant per periode & transaksi terakhir per tenant. Indeks laporan yang
 * ada diawali outlet_id sehingga tidak terpakai untuk query lintas outlet/tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'completed_at'], 'orders_platform_completed_index');
            $table->index(['tenant_id', 'status', 'completed_at'], 'orders_tenant_completed_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_platform_completed_index');
            $table->dropIndex('orders_tenant_completed_index');
        });
    }
};
