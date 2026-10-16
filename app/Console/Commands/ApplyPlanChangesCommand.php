<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\ApplyScheduledPlanChanges;
use Illuminate\Console\Command;

/** Terapkan paket langganan yang dijadwalkan di akhir masa aktif (ADR 0024 revisi). Tiap jam. */
final class ApplyPlanChangesCommand extends Command
{
    protected $signature = 'pos:apply-plan-changes';

    protected $description = 'Terapkan paket langganan terjadwal yang sudah jatuh waktunya';

    public function handle(ApplyScheduledPlanChanges $changes): int
    {
        $this->info($changes->handle().' paket terjadwal diterapkan.');

        return self::SUCCESS;
    }
}
