<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

/**
 * Input Rupiah standar (docs/standards/ui/components/money-input.md):
 * prefix Rp, ribuan bertitik saat mengetik, rata kanan, disimpan sebagai string desimal.
 * Konversi nilai lewat MoneyStateCast (bukan stripCharacters — lihat kelas tersebut).
 */
final class MoneyInput extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->prefix('Rp')
            ->inputMode('numeric')
            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
            ->stateCast(new MoneyStateCast)
            ->extraInputAttributes(['class' => 'text-right tabular-nums'])
            ->rule('decimal:0,2')
            ->rule('min:0')
            ->rule('max:9999999999999');
    }

    /** Validasi memakai nilai yang sudah dinormalkan ("22.000" → "22000"), bukan teks bermask. */
    public function mutateStateForValidation(mixed $state): mixed
    {
        return parent::mutateStateForValidation((new MoneyStateCast)->get($state));
    }
}
