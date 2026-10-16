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
    // Kategori sistem (tidak dibuat untuk setiap bisnis): poin member (ADR 0013)
    case Points = 'points';
    // Kategori sistem: pembayaran online lewat Midtrans owner (ADR 0014); hanya dicatat dari webhook terverifikasi
    case Online = 'online';
    // Kategori sistem: kasbon member (ADR 0023), aktif mengikuti setelan Program member → Kasbon
    case Receivable = 'receivable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tunai',
            self::Qris => 'QRIS',
            self::Transfer => 'Transfer',
            self::Debit => 'Debit',
            self::Credit => 'Kredit',
            self::Points => 'Poin',
            self::Online => 'Online',
            self::Receivable => 'Kasbon',
        };
    }

    /**
     * Metode bawaan setiap bisnis baru (SPEC: 5 metode). Kategori sistem dibuat oleh fiturnya sendiri.
     *
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::Cash, self::Qris, self::Transfer, self::Debit, self::Credit];
    }

    /** Dibuat & diatur oleh fitur (mis. program member), bukan metode bayar biasa. */
    public function isSystem(): bool
    {
        return $this === self::Points || $this === self::Online || $this === self::Receivable;
    }

    /** Debit & kredit wajib approval code EDC (SPEC Pembayaran). */
    public function requiresReferenceByDefault(): bool
    {
        return $this === self::Debit || $this === self::Credit;
    }
}
