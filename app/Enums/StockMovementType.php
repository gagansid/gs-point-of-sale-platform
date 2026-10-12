<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasColor, HasLabel
{
    case Adjustment = 'adjustment';
    case Sale = 'sale';
    case VoidReturn = 'void_return';
    // Dokumen stok (ADR 0015)
    case Purchase = 'purchase';
    case PurchaseVoid = 'purchase_void';
    case Opname = 'opname';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case TransferCancel = 'transfer_cancel';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adjustment => 'Penyesuaian',
            self::Sale => 'Penjualan',
            self::VoidReturn => 'Pengembalian void',
            self::Purchase => 'Stok masuk',
            self::PurchaseVoid => 'Batal stok masuk',
            self::Opname => 'Stok opname',
            self::TransferOut => 'Transfer keluar',
            self::TransferIn => 'Transfer masuk',
            self::TransferCancel => 'Batal transfer',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adjustment => 'info',
            self::Sale => 'gray',
            self::VoidReturn => 'warning',
            self::Purchase => 'success',
            self::PurchaseVoid => 'danger',
            self::Opname => 'primary',
            self::TransferOut => 'warning',
            self::TransferIn => 'success',
            self::TransferCancel => 'gray',
        };
    }
}
