<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\SendBillingReminders;
use Illuminate\Console\Command;

/** Tagihan lewat jatuh tempo → kedaluwarsa; pengingat langganan berakhir 7/3/1 hari (ADR 0024). Harian. */
final class SendBillingRemindersCommand extends Command
{
    protected $signature = 'pos:billing-reminders';

    protected $description = 'Kedaluwarsakan tagihan lewat jatuh tempo & kirim pengingat langganan berakhir';

    public function handle(SendBillingReminders $reminders): int
    {
        $result = $reminders->handle();
        $this->info("{$result['expired']} tagihan kedaluwarsa, {$result['reminded']} bisnis diingatkan.");

        return self::SUCCESS;
    }
}
