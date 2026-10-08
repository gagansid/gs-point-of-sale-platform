<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Completed = 'completed';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open bill',
            self::Completed => 'Selesai',
            self::Voided => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::Completed => 'success',
            self::Voided => 'danger',
        };
    }
}
