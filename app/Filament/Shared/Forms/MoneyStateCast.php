<?php

declare(strict_types=1);

namespace App\Filament\Shared\Forms;

use App\Support\Money;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * Konversi dua arah nilai uang di form.
 *
 * Jangan memakai stripCharacters('.') untuk uang: cast bawaannya juga berjalan saat data
 * dimuat sehingga "22000.00" dari database menjadi "2200000" (harga ×100).
 */
final class MoneyStateCast implements StateCast
{
    /** Dari form (mask "22.000" / "22.000,50") ke nilai server ("22000" / "22000.50"). */
    public function get(mixed $state): mixed
    {
        if (! is_string($state) && ! is_int($state)) {
            return $state;
        }

        $value = trim((string) $state);

        if ($value === '') {
            return null;
        }

        // Titik hanya dibuang bila polanya pemisah ribuan (1.000 / 22.000 / 1.250.000)
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $value) === 1) {
            $value = str_replace('.', '', $value);
        }

        return str_replace(',', '.', $value);
    }

    /** Dari server ("22000.00") ke tampilan form ("22000"; sen ditampilkan dengan koma). */
    public function set(mixed $state): mixed
    {
        if ($state === null || $state === '') {
            return null;
        }

        $value = (string) $state;

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return $value;
        }

        [$integer, $decimal] = explode('.', Money::of($value));

        return $decimal === '00' ? $integer : $integer.','.$decimal;
    }
}
