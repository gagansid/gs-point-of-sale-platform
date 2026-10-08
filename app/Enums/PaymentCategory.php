<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentCategory: string implements HasLabel
{
    case Cash = 'cash';
    case Qris = 'qris';
    case Transfer = 'transfer';
    case Debit = 'debit';
    case Credit = 'credit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Transfer => 'Transfer',
            self::Debit => 'Debit',
            self::Credit => 'Kredit',
        };
    }

    /** Debit & kredit wajib approval code EDC (SPEC Pembayaran). */
    public function requiresReferenceByDefault(): bool
    {
        return $this === self::Debit || $this === self::Credit;
    }
}
