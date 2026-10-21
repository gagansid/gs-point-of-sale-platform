<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Billing\PrunePaymentProofs;
use Illuminate\Console\Command;

/** Hapus bukti transfer tagihan batal/kedaluwarsa yang lewat masa simpan & file unggahan yatim (Q127). Harian. */
final class PrunePaymentProofsCommand extends Command
{
    protected $signature = 'pos:prune-payment-proofs';

    protected $description = 'Hapus bukti transfer lama (tagihan batal/kedaluwarsa) dan unggahan yang tidak terpakai';

    public function handle(PrunePaymentProofs $prune): int
    {
        $result = $prune->handle();
        $this->info("{$result['expired']} bukti tagihan lama & {$result['orphans']} file yatim dihapus.");

        return self::SUCCESS;
    }
}
