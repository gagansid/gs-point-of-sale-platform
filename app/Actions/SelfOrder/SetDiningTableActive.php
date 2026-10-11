<?php

declare(strict_types=1);

namespace App\Actions\SelfOrder;

use App\Models\DiningTable;

/** Aktif/nonaktifkan meja (izin table.manage). Nonaktif: QR menolak pesanan baru; riwayat order tetap. */
final class SetDiningTableActive
{
    public function handle(DiningTable $table, bool $active): DiningTable
    {
        $table->forceFill(['is_active' => $active])->save();

        return $table;
    }
}
