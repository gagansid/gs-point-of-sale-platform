<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AppPlatform: string implements HasLabel
{
    case Android = 'android';
    case Ios = 'ios';
    // Browser kasir web (Q54): didaftarkan otomatis, bukan lewat API /devices
    case Web = 'web';

    public function getLabel(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
            self::Web => 'Kasir web',
        };
    }
}
