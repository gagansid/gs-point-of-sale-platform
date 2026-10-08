<?php

declare(strict_types=1);

namespace App\Filament\Shared\Columns;

use App\Support\Money;
use Filament\Tables\Columns\TextColumn;

/**
 * Kolom Rupiah standar: Rp62.000, rata kanan, angka tabular (money-input.md).
 */
final class MoneyColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(fn (mixed $state): string => Money::format((string) $state))
            ->alignEnd()
            ->extraAttributes(['class' => 'is-money']);
    }
}
