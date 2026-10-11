<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis mutasi poin member (ADR 0013). */
enum PointEntryType: string implements HasLabel
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case VoidReversal = 'void_reversal';
    case Adjust = 'adjust';
    case Expire = 'expire';

    public function getLabel(): string
    {
        return match ($this) {
            self::Earn => 'Didapat',
            self::Redeem => 'Ditukar',
            self::VoidReversal => 'Batal (void)',
            self::Adjust => 'Penyesuaian',
            self::Expire => 'Kedaluwarsa',
        };
    }
}
