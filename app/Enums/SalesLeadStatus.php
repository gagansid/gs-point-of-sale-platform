<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SalesLeadStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Baru',
            self::Contacted => 'Dihubungi',
            self::Won => 'Berhasil',
            self::Lost => 'Batal',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Contacted => 'warning',
            self::Won => 'success',
            self::Lost => 'gray',
        };
    }
}
