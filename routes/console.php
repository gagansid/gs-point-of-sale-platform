<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Jadwal (SPEC → Deploy). Shared hosting: satu cron `php artisan schedule:run` tiap menit, tanpa
 * proses yang berjalan terus. Backup ditambahkan bersama spatie/laravel-backup.
 */

// Antrean database: proses sampai kosong, maks. 50 detik agar tidak bertumpuk dengan menit berikutnya
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5);

// Token Sanctum kedaluwarsa (kasir 16 jam, owner 30 hari) dibersihkan
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Token reset kata sandi kedaluwarsa
Schedule::command('auth:clear-resets')->daily();

// Ringkasan harian email (Q57): tiap jam, tiap user dikirim sekali setelah jam 07.00 waktu outletnya
Schedule::command('pos:daily-summary')->hourlyAt(5)->withoutOverlapping(30);

// Poin member kedaluwarsa (ADR 0013): dini hari WIB
Schedule::command('pos:expire-member-points')->dailyAt('01:30')->timezone('Asia/Jakarta')->withoutOverlapping(30);

// Tier member (ADR 0013, Q64): belanja lewat 12 bulan keluar dari hitungan, tier bisa turun
Schedule::command('pos:evaluate-member-tiers')->dailyAt('01:45')->timezone('Asia/Jakarta')->withoutOverlapping(30);

// Self-order (ADR 0014): pesanan meja yang tidak ditangani / tidak dibayar dalam batas waktu
Schedule::command('pos:expire-self-orders')->everyMinute()->withoutOverlapping(5);

// Voucher ulang tahun (ADR 0013, Q66): harian, member yang berulang tahun bulan ini (sekali per bulan)
Schedule::command('pos:issue-birthday-vouchers')->dailyAt('00:15')->timezone('Asia/Jakarta')->withoutOverlapping(30);

// Audit log (Q58): hapus catatan lebih dari 365 hari (config activitylog.delete_records_older_than_days)
Schedule::command('activitylog:clean --force')->dailyAt('03:30')->timezone('Asia/Jakarta');

// Backup (spatie/laravel-backup) — jam WIB, saat transaksi paling sepi
Schedule::command('backup:run')->dailyAt('02:00')->timezone('Asia/Jakarta')->withoutOverlapping(60);
Schedule::command('backup:clean')->dailyAt('03:00')->timezone('Asia/Jakarta');
Schedule::command('backup:monitor')->dailyAt('04:00')->timezone('Asia/Jakarta');

// Tagihan langganan SaaS (ADR 0024): kedaluwarsa & pengingat berakhir 7/3/1 hari
Schedule::command('pos:billing-reminders')->dailyAt('08:00')->timezone('Asia/Jakarta')->withoutOverlapping(30);
// Paket terjadwal (ADR 0024 revisi): downgrade / ganti siklus berlaku di akhir masa aktif lama
Schedule::command('pos:apply-plan-changes')->hourlyAt(2)->withoutOverlapping(30);
