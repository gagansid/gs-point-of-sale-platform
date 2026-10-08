<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Paid = 'paid';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Paid => 'Lunas',
            self::Voided => 'Void',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Voided => 'danger',
        };
    }
}
