<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit pembuat & pengubah (RecordsAuthor). Tanpa foreign key: pelaku boleh dinonaktifkan/dihapus lunak
 * tanpa memengaruhi data; nilai lama tetap menunjuk id pelaku. tenants & sales_leads menunjuk admins.
 */
return new class extends Migration
{
    private const TABLES = [
        'categories', 'products', 'option_groups', 'payment_methods', 'outlets', 'users',
        'orders', 'shifts', 'tenants', 'sales_leads',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn(['created_by', 'updated_by']);
            });
        }
    }
};
