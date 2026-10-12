<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Models\DiningTable;

/** Ganti token QR & URL pendek meja (ADR 0014, Q69): kartu lama langsung tidak berlaku, mis. bila QR difoto & disalahgunakan. */
final class RegenerateTableQr
{
    public function handle(DiningTable $table): DiningTable
    {
        // URL pendek ikut diganti: kartu lama (QR & tulisan) tidak berlaku lagi
        $table->forceFill(['qr_token' => DiningTable::newToken(), 'short_code' => DiningTable::newShortCode()])->save();
        activity('DiningTable')->performedOn($table)->event('updated')->log('qr_regenerated');

        return $table;
    }
}
