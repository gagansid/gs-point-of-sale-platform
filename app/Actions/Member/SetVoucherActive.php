<?php

declare(strict_types=1);

namespace App\Actions\Member;

use App\Models\Voucher;

/** Aktif/nonaktifkan voucher (izin member.settings); nonaktif = tidak bisa dipakai, riwayat pemakaian tetap. */
final class SetVoucherActive
{
    public function handle(Voucher $voucher, bool $active): Voucher
    {
        $voucher->forceFill(['is_active' => $active])->save();

        return $voucher;
    }
}
