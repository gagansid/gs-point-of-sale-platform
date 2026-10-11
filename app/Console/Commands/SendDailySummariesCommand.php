<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Report\SendDailySummaries;
use Illuminate\Console\Command;

/** Ringkasan harian lewat email (Q57). Dijadwalkan tiap jam; aman dijalankan berulang. */
final class SendDailySummariesCommand extends Command
{
    protected $signature = 'pos:daily-summary';

    protected $description = 'Kirim ringkasan penjualan kemarin ke email owner/manager yang berlangganan';

    public function handle(SendDailySummaries $send): int
    {
        $count = $send->handle();
        $this->info("{$count} ringkasan harian diantrekan.");

        return self::SUCCESS;
    }
}
