<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Asal voucher (ADR 0013, Q66). */
enum VoucherSource: string implements HasLabel
{
    // Kode promo dibuat owner
    case Owner = 'owner';
    // Voucher pribadi otomatis di bulan lahir member
    case Birthday = 'birthday';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Kode promo',
            self::Birthday => 'Ulang tahun',
        };
    }
}
