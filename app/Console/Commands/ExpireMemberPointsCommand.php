<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Member\ExpireMemberPoints;
use Illuminate\Console\Command;

/** Hanguskan poin member yang lewat masa berlaku (ADR 0013). Dijadwalkan harian; aman diulang. */
final class ExpireMemberPointsCommand extends Command
{
    protected $signature = 'pos:expire-member-points';

    protected $description = 'Hanguskan poin member yang sudah lewat masa berlaku';

    public function handle(ExpireMemberPoints $expire): int
    {
        $this->info($expire->handle().' poin dihanguskan.');

        return self::SUCCESS;
    }
}
