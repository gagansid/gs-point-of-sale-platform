<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cara uang retur dikembalikan (ADR 0018). */
enum RefundMethod: string implements HasLabel
{
    /** Kas keluar dari laci shift berjalan */
    case Cash = 'cash';

    /** Transfer/QRIS dll. diproses di luar sistem; hanya dicatat */
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tunai dari laci',
            self::Manual => 'Manual (transfer/QRIS)',
        };
    }
}
