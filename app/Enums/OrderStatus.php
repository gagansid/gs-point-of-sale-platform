<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    // Self-order diterima kasir, menunggu dibayar (ADR 0014)
    case AwaitingPayment = 'awaiting_payment';
    case Completed = 'completed';
    case Voided = 'voided';
    // Self-order tidak dibayar dalam batas waktu (ADR 0014)
    case Expired = 'expired';
    // Open bill digabung ke bill lain (ADR 0019)
    case Merged = 'merged';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open bill',
            self::AwaitingPayment => 'Menunggu bayar',
            self::Completed => 'Selesai',
            self::Voided => 'Void',
            self::Expired => 'Kedaluwarsa',
            self::Merged => 'Digabung',
        };
    }

    /** Belum lunas & masih bisa dibayar: open bill kasir atau self-order yang sudah diterima. */
    public function isPayable(): bool
    {
        return $this === self::Open || $this === self::AwaitingPayment;
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info',
            self::AwaitingPayment => 'warning',
            self::Completed => 'success',
            self::Voided => 'danger',
            self::Expired => 'gray',
            self::Merged => 'gray',
        };
    }
}
