<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AppPlatform: string implements HasLabel
{
    case Android = 'android';
    case Ios = 'ios';

    public function getLabel(): string
    {
        return match ($this) {
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }
}
