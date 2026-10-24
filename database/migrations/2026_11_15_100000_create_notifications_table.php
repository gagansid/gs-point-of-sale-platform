<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kotak notifikasi lonceng /dashboard & /admin (Q139): tabel notifikasi standar Laravel, satu baris per penerima
 * (User atau Admin). Diisi AlertInbox dari peringatan yang dihitung (DashboardAlerts, AdminAlerts). `hidden_at` = dihapus
 * penerima saat peringatannya masih aktif: baris disimpan agar tidak muncul lagi sebagai notifikasi baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'hidden_at', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
