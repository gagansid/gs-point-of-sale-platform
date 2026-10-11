<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * Backup manual dari panel admin, dijalankan antrean (cron tiap menit) — bukan di request web yang
 * dibatasi max_execution_time shared hosting. Satu backup sekaligus.
 */
final class RunBackup implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('backup:run');
    }
}
