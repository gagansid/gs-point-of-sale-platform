<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Status dokumen stok masuk / opname (ADR 0015). */
enum StockDocumentStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Posted => 'success',
            self::Cancelled => 'danger',
        };
    }
}
