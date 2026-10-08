<?php

declare(strict_types=1);

namespace App\Filament\Shared\Infolists;

use App\Support\Money;
use Filament\Infolists\Components\TextEntry;

/**
 * Nilai Rupiah di infolist (infolist.md): Rp62.000, rata kanan, angka tabular.
 */
final class MoneyEntry extends TextEntry
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn (mixed $state): string => Money::format((string) ($state ?? '0')))
            ->alignEnd()
            ->extraAttributes(['class' => 'is-money']);
    }
}
