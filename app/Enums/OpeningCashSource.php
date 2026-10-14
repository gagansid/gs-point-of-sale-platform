<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Asal kas awal shift (ADR 0017). */
enum OpeningCashSource: string implements HasColor, HasLabel
{
    /** Diketik saat buka (perangkat tanpa shift sebelumnya yang tercatat sisa lacinya) */
    case Manual = 'manual';

    /** Sisa laci shift terakhir di perangkat yang sama */
    case CarryOver = 'carry_over';

    /** Carry-over dikoreksi (izin shift.edit_opening_cash atau PIN approval) */
    case Adjusted = 'adjusted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Diisi manual',
            self::CarryOver => 'Sisa laci shift sebelumnya',
            self::Adjusted => 'Dikoreksi',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::CarryOver => 'info',
            self::Adjusted => 'warning',
        };
    }
}
