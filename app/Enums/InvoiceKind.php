<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Jenis tagihan langganan (ADR 0024 revisi): perpanjangan masa aktif, atau upgrade prorata sisa masa aktif. */
enum InvoiceKind: string implements HasLabel
{
    case Renewal = 'renewal';
    case Upgrade = 'upgrade';

    public function getLabel(): string
    {
        return $this === self::Renewal ? 'Perpanjangan' : 'Upgrade';
    }
}
