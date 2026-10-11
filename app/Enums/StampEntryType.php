<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis mutasi stempel member (ADR 0013, Q65). */
enum StampEntryType: string implements HasLabel
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case VoidReversal = 'void_reversal';
    case Adjust = 'adjust';

    public function getLabel(): string
    {
        return match ($this) {
            self::Earn => 'Didapat',
            self::Redeem => 'Ditukar',
            self::VoidReversal => 'Batal (void)',
            self::Adjust => 'Penyesuaian',
        };
    }
}
