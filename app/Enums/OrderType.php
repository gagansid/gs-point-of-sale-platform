<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderType: string implements HasLabel
{
    case Takeaway = 'takeaway';
    case DineIn = 'dine_in';

    public function getLabel(): string
    {
        return match ($this) {
            self::Takeaway => 'Bawa pulang',
            self::DineIn => 'Makan di tempat',
        };
    }
}
