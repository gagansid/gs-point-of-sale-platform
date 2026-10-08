<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasColor, HasLabel
{
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case VoidReturn = 'void_return';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adjustment => 'Penyesuaian',
            self::Sale => 'Penjualan',
            self::VoidReturn => 'Pengembalian void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adjustment => 'info',
            self::Sale => 'gray',
            self::VoidReturn => 'warning',
        };
    }
}
