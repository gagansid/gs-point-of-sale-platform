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
