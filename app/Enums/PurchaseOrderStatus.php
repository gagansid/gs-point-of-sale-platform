<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Status purchase order (ADR 0021). */
enum PurchaseOrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draf',
            self::Ordered => 'Dikirim',
            self::Partial => 'Diterima sebagian',
            self::Received => 'Diterima',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::Partial => 'warning',
            self::Received => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Masih menunggu barang (bisa diterima / dibatalkan). */
    public function isOpen(): bool
    {
        return $this === self::Ordered || $this === self::Partial;
    }
}
