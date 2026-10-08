<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShiftStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Closed = 'closed';
    case ForceClosed = 'force_closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Berjalan',
            self::Closed => 'Ditutup',
            self::ForceClosed => 'Ditutup paksa',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Closed => 'gray',
            self::ForceClosed => 'warning',
        };
    }
}
