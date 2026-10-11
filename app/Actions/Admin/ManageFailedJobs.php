<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\FailedJob;
use Illuminate\Support\Facades\Artisan;

/**
 * Job gagal (Sistem → Queue): ulangi atau hapus lewat perintah artisan bawaan agar perilakunya sama
 * dengan `php artisan queue:retry` / `queue:forget` / `queue:flush`.
 */
final class ManageFailedJobs
{
    /** @param list<string> $uuids */
    public function retry(array $uuids): void
    {
        if ($uuids !== []) {
            Artisan::call('queue:retry', ['id' => $uuids]);
        }
    }

    public function retryAll(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
    }

    public function forget(FailedJob $job): void
    {
        Artisan::call('queue:forget', ['id' => $job->uuid]);
    }

    public function flush(): void
    {
        Artisan::call('queue:flush');
    }
}
