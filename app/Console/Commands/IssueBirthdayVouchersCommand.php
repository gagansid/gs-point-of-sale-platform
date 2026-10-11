<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Member\IssueBirthdayVouchers;
use Illuminate\Console\Command;

/** Buat voucher ulang tahun member bulan ini (ADR 0013). Dijadwalkan harian; aman diulang. */
final class IssueBirthdayVouchersCommand extends Command
{
    protected $signature = 'pos:issue-birthday-vouchers';

    protected $description = 'Buat voucher ulang tahun untuk member yang berulang tahun bulan ini';

    public function handle(IssueBirthdayVouchers $issue): int
    {
        $this->info($issue->handle().' voucher ulang tahun dibuat.');

        return self::SUCCESS;
    }
}
