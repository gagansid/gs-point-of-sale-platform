<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Device\PruneIdleWebDevices;
use Illuminate\Console\Command;

/** Cabut device kasir web menganggur yang menghabiskan kuota perangkat (Q117). Harian; aman diulang. */
final class PruneIdleWebDevicesCommand extends Command
{
    protected $signature = 'pos:prune-web-devices';

    protected $description = 'Cabut perangkat kasir web tanpa shift terbuka yang lama tidak dipakai';

    public function handle(PruneIdleWebDevices $prune): int
    {
        $this->info($prune->handle().' perangkat kasir web menganggur dicabut.');

        return self::SUCCESS;
    }
}
