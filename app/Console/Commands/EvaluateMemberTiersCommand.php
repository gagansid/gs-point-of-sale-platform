<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Member\EvaluateMemberTiers;
use Illuminate\Console\Command;

/** Evaluasi ulang tier member dari belanja 12 bulan bergulir (ADR 0013). Dijadwalkan harian; aman diulang. */
final class EvaluateMemberTiersCommand extends Command
{
    protected $signature = 'pos:evaluate-member-tiers';

    protected $description = 'Hitung ulang tier member dari total belanja 12 bulan terakhir';

    public function handle(EvaluateMemberTiers $evaluate): int
    {
        $this->info($evaluate->handle().' member berubah tier.');

        return self::SUCCESS;
    }
}
