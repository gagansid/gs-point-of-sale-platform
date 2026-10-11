<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Asal order (ADR 0014): kasir atau pesan sendiri lewat QR meja. */
enum OrderChannel: string implements HasLabel
{
    case Pos = 'pos';
    case SelfOrder = 'self_order';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pos => 'Kasir',
            self::SelfOrder => 'QR meja',
        };
    }
}
