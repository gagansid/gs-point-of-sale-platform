<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Fulfillment\AutoCompleteFulfillment;
use Illuminate\Console\Command;

/** Tutup otomatis pesanan meja lunas yang tidak ditandai Diantar setelah batas outlet (ADR 0027 K4). Tiap 5 menit. */
final class AutoCompleteFulfillmentCommand extends Command
{
    protected $signature = 'pos:auto-complete-fulfillment';

    protected $description = 'Selesaikan otomatis pesanan meja yang tidak ditandai Diantar';

    public function handle(AutoCompleteFulfillment $complete): int
    {
        $this->info($complete->handle().' pesanan meja selesai otomatis.');

        return self::SUCCESS;
    }
}
