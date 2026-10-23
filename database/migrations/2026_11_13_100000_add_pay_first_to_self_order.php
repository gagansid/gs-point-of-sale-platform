<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesan dari meja bayar dulu (ADR 0027, Q137): QRIS Core API tampil di halaman meja (qr_string & kedaluwarsa disimpan),
 * metode bayar di HP per outlet, dan batas selesai otomatis pengantaran. Additive; nilai bawaan = perilaku lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_transactions', function (Blueprint $table): void {
            // snap (redirect, e-wallet/VA) atau qris (Core API, QR di halaman)
            $table->string('method', 10)->default('snap')->after('status');
            $table->text('qr_string')->nullable()->after('redirect_url');
            $table->timestamp('expires_at')->nullable()->after('qr_string');
        });

        Schema::table('self_order_settings', function (Blueprint $table): void {
            $table->boolean('allow_qris')->default(true)->after('allow_pay_at_cashier');
            $table->boolean('allow_points')->default(true)->after('allow_qris');
            // Pesanan lunas yang tidak ditandai Diantar ditutup otomatis setelah ini (menit sejak lunas)
            $table->unsignedSmallInteger('auto_complete_minutes')->default(60)->after('allow_points');
        });
    }

    public function down(): void
    {
        Schema::table('self_order_settings', fn (Blueprint $table) => $table->dropColumn(['allow_qris', 'allow_points', 'auto_complete_minutes']));
        Schema::table('gateway_transactions', fn (Blueprint $table) => $table->dropColumn(['method', 'qr_string', 'expires_at']));
    }
};
