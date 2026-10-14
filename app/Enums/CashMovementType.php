<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Arah kas masuk/keluar laci selama shift (ADR 0017). */
enum CashMovementType: string implements HasColor, HasLabel
{
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::In => 'Kas masuk',
            self::Out => 'Kas keluar',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::In => 'success',
            self::Out => 'danger',
        };
    }
}
