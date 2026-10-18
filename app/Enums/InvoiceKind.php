<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Jenis tagihan langganan (ADR 0024 revisi, ADR 0025 C2): perpanjangan masa aktif (paket + add-on), upgrade prorata
 * sisa masa aktif, atau tambah add-on prorata sisa masa aktif.
 */
enum InvoiceKind: string implements HasLabel
{
    case Renewal = 'renewal';
    case Upgrade = 'upgrade';
    case Addon = 'addon';

    public function getLabel(): string
    {
        return match ($this) {
            self::Renewal => 'Perpanjangan',
            self::Upgrade => 'Upgrade',
            self::Addon => 'Add-on',
        };
    }
}
