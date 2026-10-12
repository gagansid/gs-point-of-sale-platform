<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Status transfer antar outlet (ADR 0015, Q82). */
enum StockTransferStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::InTransit => 'Dalam perjalanan',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::InTransit => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }
}
