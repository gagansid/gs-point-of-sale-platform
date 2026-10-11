<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Models\DiningTable;

/** Ganti token QR meja (ADR 0014, Q69): QR lama langsung tidak berlaku, mis. bila QR difoto & disalahgunakan. */
final class RegenerateTableQr
{
    public function handle(DiningTable $table): DiningTable
    {
        $table->forceFill(['qr_token' => DiningTable::newToken()])->save();
        activity('DiningTable')->performedOn($table)->event('updated')->log('qr_regenerated');

        return $table;
    }
}
