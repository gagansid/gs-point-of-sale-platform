<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Mutasi kasbon member (ADR 0023). */
enum ReceivableEntryType: string implements HasColor, HasLabel
{
    case Charge = 'charge';
    case Payment = 'payment';
    case Void = 'void';
    case Refund = 'refund';

    public function getLabel(): string
    {
        return match ($this) {
            self::Charge => 'Kasbon',
            self::Payment => 'Pelunasan',
            self::Void => 'Batal (void)',
            self::Refund => 'Retur',
        };
    }

    public function getColor(): string
    {
        return $this === self::Charge ? 'warning' : 'success';
    }
}
