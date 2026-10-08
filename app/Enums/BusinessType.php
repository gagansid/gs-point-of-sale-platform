<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BusinessType: string implements HasLabel
{
    case Cafe = 'cafe';
    case Retail = 'retail';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cafe => 'Kafe / resto',
            self::Retail => 'Retail',
            self::Other => 'Lainnya',
        };
    }
}
