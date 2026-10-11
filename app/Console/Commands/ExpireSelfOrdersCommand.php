<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SelfOrder\ExpireSelfOrders;
use Illuminate\Console\Command;

/** Kedaluwarsakan pesanan meja yang tidak ditangani / tidak dibayar dalam batas waktu (ADR 0014). Tiap menit. */
final class ExpireSelfOrdersCommand extends Command
{
    protected $signature = 'pos:expire-self-orders';

    protected $description = 'Kedaluwarsakan pesanan meja yang lewat batas waktu';

    public function handle(ExpireSelfOrders $expire): int
    {
        $this->info($expire->handle().' pesanan meja kedaluwarsa.');

        return self::SUCCESS;
    }
}
