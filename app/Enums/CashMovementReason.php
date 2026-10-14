<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Kategori alasan kas masuk/keluar (ADR 0017 K2). Catatan bebas wajib untuk "Lainnya".
 */
enum CashMovementReason: string implements HasLabel
{
    /** Belanja operasional (galon, es batu, gas) — hanya kas keluar */
    case Operational = 'operational';

    /** Uang laci disetor ke owner/brankas di tengah shift — hanya kas keluar */
    case Deposit = 'deposit';

    /** Tukar/tambah uang kembalian — masuk atau keluar */
    case Change = 'change';

    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operational => 'Belanja operasional',
            self::Deposit => 'Setor ke owner',
            self::Change => 'Tukar/tambah kembalian',
            self::Other => 'Lainnya',
        };
    }

    public function allows(CashMovementType $type): bool
    {
        return match ($this) {
            self::Operational, self::Deposit => $type === CashMovementType::Out,
            self::Change, self::Other => true,
        };
    }

    /** @return list<self> */
    public static function for(CashMovementType $type): array
    {
        return array_values(array_filter(self::cases(), fn (self $reason): bool => $reason->allows($type)));
    }
}
